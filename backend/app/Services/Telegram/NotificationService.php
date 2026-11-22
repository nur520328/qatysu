<?php

namespace App\Services\Telegram;

use App\Enums\Role;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\AttendanceRoundConflict;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\User;
use App\Services\Attendance\DailySummaryService;
use App\Services\CalendarService;
use App\Services\Reports\PeriodReport;
use App\Services\Reports\ReportExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Telegram хабарлары (docs/кезең-10-жоспар.md, 7–14-шешім). Минут сайын: `collect()` дерекқордан не хабарлау
 * керегін анықтап кезекке жазады, `send()` кезекті жібереді. Сұраныс ішінде ештеңе жіберілмейді.
 * Мәтіндерде оқушының аты-жөні де, себебі де болмайды: тек сынып пен сан.
 */
class NotificationService
{
    public const MAX_ATTEMPTS = 5;

    /** Осыдан ескі хабар жіберілмейді. */
    public const MAX_AGE_HOURS = 12;

    /** Бір іске қосылғанда ең көп жіберілетін хабар саны (Telegram шегі мен хостинг уақытына сыйуы үшін). */
    public const BATCH = 30;

    /** Шешім күтіп тұрған жазбалар туралы еске салу уақыты. */
    public const REMINDER_HOUR = 19;

    private const LISTED_CLASSES = 15;

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly TelegramClient $client,
        private readonly DailySummaryService $summary,
        private readonly CalendarService $calendar,
        private readonly ReportExport $export,
    ) {}

    /** Кезекке жаңа хабарларды қосады. Қосылған хабар санын қайтарады. */
    public function collect(): int
    {
        if (! $this->telegram->ready()) {
            return 0;
        }
        $linked = User::query()->whereNotNull('telegram_chat_id')->where('is_active', true)->get();
        if ($linked->isEmpty()) {
            return 0;
        }
        $admins = $linked->where('role', Role::Admin)->values();
        $today = CarbonImmutable::today();
        $before = NotificationLog::query()->count();

        $this->homeroomEvents($linked->keyBy('id'), $today);
        if ($admins->isNotEmpty()) {
            $this->conflicts($admins, $today);
            $this->shiftSummaries($admins, $today);
            $this->reports($admins);
        }
        if (now()->hour >= self::REMINDER_HOUR) {
            $this->pendingReminders($linked, $today);
        }

        return NotificationLog::query()->count() - $before;
    }

    /**
     * Кезектегі хабарларды жібереді.
     *
     * @return array{sent: int, failed: int, left: int}
     */
    public function send(): array
    {
        NotificationLog::query()->where('status', NotificationLog::PENDING)
            ->where('created_at', '<', now()->subHours(self::MAX_AGE_HOURS))->update(['status' => NotificationLog::EXPIRED]);

        $sent = $failed = 0;
        $rows = NotificationLog::query()->where('status', NotificationLog::PENDING)->with(['user', 'report'])->orderBy('id')->limit(self::BATCH)->get();
        foreach ($rows as $row) {
            $chatId = $row->user?->is_active ? $row->user->telegram_chat_id : null;
            if ($chatId === null) {
                $row->update(['status' => NotificationLog::FAILED, 'error' => 'unlinked']);
                $failed++;

                continue;
            }
            try {
                if ($row->report === null) {
                    $this->client->sendMessage($chatId, $row->text);
                } else {
                    $this->client->sendDocument($chatId, $this->reportFileName($row->report), $this->export->build(
                        PeriodReport::withoutNames($row->report->data), $row->user->locale->value, snapshot: true,
                    ), $row->text);
                }
                $row->update(['status' => NotificationLog::SENT, 'sent_at' => now(), 'attempts' => $row->attempts + 1, 'error' => null]);
                $sent++;
            } catch (TelegramException $e) {
                $attempts = $row->attempts + 1;
                if ($e->recipientGone()) {
                    // Пайдаланушы ботты бұғаттаған не чатты өшірген: байланыс үзіледі, қайта жіберілмейді.
                    $this->telegram->unlink($row->user);
                    $row->update(['status' => NotificationLog::FAILED, 'attempts' => $attempts, 'error' => 'recipient_gone']);
                    $failed++;

                    continue;
                }
                $giveUp = $attempts >= self::MAX_ATTEMPTS;
                $row->update(['status' => $giveUp ? NotificationLog::FAILED : NotificationLog::PENDING, 'attempts' => $attempts, 'error' => mb_substr($e->getMessage(), 0, 250)]);
                $failed += $giveUp ? 1 : 0;
                if ($e->retryAfter !== null || $e->getMessage() === 'unreachable') {
                    break; // Telegram «сәл күтіңіз» деді не қолжетімсіз: қалғаны келесі минутта.
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed, 'left' => NotificationLog::query()->where('status', NotificationLog::PENDING)->count()];
    }

    /**
     * Жетекшіге: сыныбы аралынды (N оқушы жоқ) және оқушы кетіп қалды.
     *
     * @param  Collection<int, User>  $linked
     */
    private function homeroomEvents(Collection $linked, CarbonImmutable $today): void
    {
        $classes = SchoolClass::query()->whereIn('homeroom_user_id', $linked->keys())->get()->keyBy('id');
        if ($classes->isEmpty()) {
            return;
        }
        $rounds = AttendanceRound::query()->whereIn('class_id', $classes->keys())->whereDate('date', $today->toDateString())->get()->keyBy('id');
        if ($rounds->isEmpty()) {
            return;
        }
        $absent = AttendanceRecord::query()->whereIn('round_id', $rounds->keys())
            ->where('round_status', 'absent')->whereNull('arrived_lesson')->whereNull('left_lesson')
            ->selectRaw('round_id, count(*) as total')->groupBy('round_id')->pluck('total', 'round_id');

        foreach ($rounds as $round) {
            $class = $classes[$round->class_id];
            $user = $linked[$class->homeroom_user_id];
            $url = $this->site("/homeroom/{$class->id}/{$today->toDateString()}");
            if ($round->duty_done && (int) ($absent[$round->id] ?? 0) > 0) {
                $this->queue($user, 'round_absent', "round:{$round->id}", ['class' => $class->name(), 'count' => (int) $absent[$round->id], 'url' => $url]);
            }
        }
        $leavers = AttendanceRecord::query()->whereIn('round_id', $rounds->keys())->whereNotNull('left_lesson')->get(['id', 'round_id', 'left_lesson']);
        foreach ($leavers as $record) {
            $class = $classes[$rounds[$record->round_id]->class_id];
            $this->queue($linked[$class->homeroom_user_id], 'left', "left:{$record->id}", [
                'class' => $class->name(), 'lesson' => $record->left_lesson, 'url' => $this->site("/homeroom/{$class->id}/{$today->toDateString()}"),
            ]);
        }
    }

    /** @param  Collection<int, User>  $admins */
    private function conflicts(Collection $admins, CarbonImmutable $today): void
    {
        $conflicts = AttendanceRoundConflict::query()->whereDate('date', $today->toDateString())->whereNull('resolved_at')->with('schoolClass')->get();
        foreach ($conflicts as $conflict) {
            foreach ($admins as $admin) {
                $this->queue($admin, 'conflict', "conflict:{$conflict->id}", ['class' => $conflict->schoolClass->name(), 'url' => $this->site('/admin/duty')]);
            }
        }
    }

    /** @param  Collection<int, User>  $admins */
    private function shiftSummaries(Collection $admins, CarbonImmutable $today): void
    {
        $date = $today->toDateString();
        if (! $this->calendar->isSchoolDay($today)) {
            return;
        }
        // Екі сменаның да хабары барлық завучқа кетіп қойса, жиынтықты қайта есептеудің қажеті жоқ.
        $done = NotificationLog::query()->whereIn('user_id', $admins->pluck('id'))->whereIn('dedupe_key', ["shift:{$date}:morning", "shift:{$date}:afternoon"])->count();
        if ($done >= $admins->count() * 2) {
            return;
        }
        foreach ($this->summary->forDate($today)['shifts'] as $shift) {
            if ($shift['classes_total'] === 0 || ! ($shift['ended'] || $shift['classes_surveyed'] === $shift['classes_total'])) {
                continue;
            }
            $missing = array_column(array_filter($shift['classes'], static fn (array $c): bool => ! $c['surveyed']), 'name');
            foreach ($admins as $admin) {
                $locale = $admin->locale->value;
                $names = implode(', ', array_slice($missing, 0, self::LISTED_CLASSES));
                if (count($missing) > self::LISTED_CLASSES) {
                    $names = __('bot.and_more', ['classes' => $names, 'count' => count($missing) - self::LISTED_CLASSES], $locale);
                }
                $this->queue($admin, 'shift_summary', "shift:{$date}:{$shift['shift']}", [
                    'shift' => __('bot.shift_'.$shift['shift'], [], $locale),
                    'date' => $today->format('d.m.Y'),
                    'surveyed' => $shift['classes_surveyed'], 'total' => $shift['classes_total'],
                    'absent' => $shift['absent'], 'late' => $shift['late'], 'left' => $shift['left'],
                    'missing' => $missing === [] ? '' : __('bot.shift_missing', ['classes' => $names], $locale),
                    'url' => $this->site("/admin/summary?date={$date}"),
                ]);
            }
        }
    }

    /** @param  Collection<int, User>  $admins */
    private function reports(Collection $admins): void
    {
        foreach (Report::query()->where('generated_at', '>=', now()->subDays(2))->get() as $report) {
            $totals = $report->data['totals'];
            $path = $report->type === Report::WEEKLY ? '/admin/reports' : '/admin/reports/monthly';
            foreach ($admins as $admin) {
                $this->queue($admin, 'report_'.$report->type, "report:{$report->id}", [
                    'from' => $report->period_start->format('d.m.Y'), 'to' => $report->period_end->format('d.m.Y'),
                    'pct' => $totals['attendance_pct'] === null ? __('bot.no_data', [], $admin->locale->value) : $totals['attendance_pct'].'%',
                    'surveyed' => $totals['class_days_surveyed'], 'total' => $totals['class_days_expected'],
                    'unexcused' => $totals['unexcused'], 'pending' => $totals['pending'],
                    'url' => $this->site("{$path}?date={$report->period_start->toDateString()}"),
                ], $report->id);
            }
        }
    }

    /** @param  Collection<int, User>  $linked */
    private function pendingReminders(Collection $linked, CarbonImmutable $today): void
    {
        $from = $today->subDays(Setting::int('homeroom_edit_days'))->toDateString();
        $counts = DB::table('attendance_records')
            ->join('attendance_rounds', 'attendance_rounds.id', '=', 'attendance_records.round_id')
            ->join('classes', 'classes.id', '=', 'attendance_rounds.class_id')
            ->whereIn('classes.homeroom_user_id', $linked->pluck('id'))->where('classes.is_active', true)
            ->whereDate('attendance_rounds.date', '>=', $from)->whereDate('attendance_rounds.date', '<=', $today->toDateString())
            ->whereIn('attendance_records.id', AttendanceRecord::query()->pendingDecision()->select('id'))
            ->selectRaw('classes.homeroom_user_id as user_id, count(*) as total')->groupBy('classes.homeroom_user_id')->pluck('total', 'user_id');
        foreach ($linked as $user) {
            if ((int) ($counts[$user->id] ?? 0) > 0) {
                $this->queue($user, 'pending', "pending:{$today->toDateString()}", ['count' => (int) $counts[$user->id], 'url' => $this->site('/')]);
            }
        }
    }

    /** @param  array<string, int|string>  $replace */
    private function queue(User $user, string $type, string $key, array $replace, ?int $reportId = null): void
    {
        NotificationLog::query()->insertOrIgnore([
            'user_id' => $user->id, 'type' => $type, 'dedupe_key' => $key, 'text' => __('bot.'.$type, $replace, $user->locale->value),
            'report_id' => $reportId, 'status' => NotificationLog::PENDING, 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function site(string $path): string
    {
        return rtrim((string) config('app.url'), '/').$path;
    }

    private function reportFileName(Report $report): string
    {
        $label = $report->type === Report::WEEKLY ? 'apta-'.$report->period_start->toDateString() : 'ai-'.$report->period_start->format('Y-m');

        return "qatysu-{$label}-jiyntyq.xlsx";
    }
}
