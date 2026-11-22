<?php

namespace App\Services\Reports;

use App\Enums\Shift;
use App\Models\AttendanceRecord;
use App\Models\CalendarDay;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\Attendance\ClassScope;
use App\Services\Duty\DutyCodeService;
use App\Support\KazakhSort;
use App\Support\StudentNames;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Кезең есебін (апта не ай) жазбалардан есептейді: екі есептің ережесі бір жерде (docs/кезең-8-жоспар.md,
 * docs/кезең-9-жоспар.md). Ештеңе сақтамайды: бекітуді `ReportSnapshotService` жасайды.
 * Нәтиже тілге тәуелсіз: мәтіндер сайтта не Excel жасағанда қойылады.
 */
class PeriodReport
{
    /** «Назар аударатын оқушылар» тізімінің ең үлкен ұзындығы. */
    public const STUDENTS_LIMIT = 50;

    /**
     * `marked` пен `absent_marked` тек кезекші аралаған күндердің жазбалары (пайыздың негізі);
     * қалғандары барлық тіркелген жазбалар бойынша.
     */
    public const COUNTERS = [
        'marked' => 0, 'absent_marked' => 0, 'absent' => 0, 'sick' => 0, 'excused' => 0, 'unexcused' => 0, 'pending' => 0,
        'late' => 0, 'left' => 0, 'left_permitted' => 0, 'left_unpermitted' => 0, 'left_pending' => 0,
    ];

    public function __construct(
        private readonly ClassScope $scope,
        private readonly DutyCodeService $codes,
    ) {}

    /**
     * Кезеңнің өтіп кеткен оқу күндері (бүгінді қоса), өсу ретімен.
     *
     * @return list<string>
     */
    public function elapsedSchoolDays(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $last = min($to->toDateString(), CarbonImmutable::today()->toDateString());

        return CalendarDay::query()->where('is_school_day', true)
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $last)
            ->orderBy('date')->pluck('date')->map(static fn ($d): string => CarbonImmutable::parse($d)->toDateString())->all();
    }

    /** Кезең аяқталды ма: оқу күні қалған жоқ және соңғы оқу күнінің түстен кейінгі сменасы бітті. */
    public function isComplete(CarbonImmutable $from, CarbonImmutable $to): bool
    {
        $today = CarbonImmutable::today();
        if ($to->lessThan($today)) {
            return true;
        }
        if ($from->greaterThan($today)) {
            return false;
        }
        $remaining = CalendarDay::query()->where('is_school_day', true)
            ->whereDate('date', '>', $today->toDateString())->whereDate('date', '<=', $to->toDateString())->exists();
        if ($remaining) {
            return false;
        }
        $days = $this->elapsedSchoolDays($from, $to);

        return $days === [] || end($days) !== $today->toDateString() || $this->codes->shiftEnd($today, Shift::Afternoon)->isPast();
    }

    /** @return array<string, mixed> */
    public function compute(CarbonImmutable $from, CarbonImmutable $to, string $type): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $days = $this->elapsedSchoolDays($from, $to);
        // Тізім мен аттастар кезеңнің соңғы өткен оқу күніндегі күй бойынша алынады.
        $anchor = CarbonImmutable::parse($days === [] ? min($to->toDateString(), max($from->toDateString(), CarbonImmutable::today()->toDateString())) : end($days));

        $rounds = DB::table('attendance_rounds')->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString())
            ->get(['id', 'class_id', 'date', 'duty_done']);
        $classes = $this->scope->forDay($anchor, $rounds->pluck('class_id')->unique()->values()->all());
        $rosterSizes = StudentEnrollment::query()->whereIn('class_id', $classes->keys())->activeOn($anchor->toDateString())
            ->selectRaw('class_id, count(*) as total')->groupBy('class_id')->pluck('total', 'class_id');
        // Бір айда ондаған мың жазба болады: модельге айналдырмай оқылады (docs/кезең-9-жоспар.md, 8-шешім).
        $records = DB::table('attendance_records')->whereIn('round_id', $rounds->pluck('id'))
            ->get(['round_id', 'student_id', 'round_status', 'arrived_lesson', 'left_lesson', 'absence_reason', 'leave_permission'])
            ->groupBy('round_id');

        $byClass = [];
        $byDay = array_fill_keys($days, self::COUNTERS + ['class_days_surveyed' => 0]);
        $perStudent = [];
        $events = [];
        foreach ($rounds as $round) {
            if (! $classes->has($round->class_id)) {
                continue;
            }
            $date = CarbonImmutable::parse($round->date)->toDateString();
            $surveyed = (bool) $round->duty_done;
            $class = $byClass[$round->class_id] ??= self::COUNTERS + ['surveyed' => []];
            $day = $byDay[$date] ?? null;
            if ($surveyed) {
                $class['surveyed'][$date] = true;
                if ($day !== null) {
                    $day['class_days_surveyed']++;
                }
            }
            foreach ($records->get($round->id) ?? [] as $record) {
                $full = AttendanceRecord::fullAbsence($record->round_status, $record->arrived_lesson, $record->left_lesson);
                $delta = $this->delta($record, $full, $surveyed);
                foreach ($delta as $key => $value) {
                    $class[$key] += $value;
                    if ($day !== null) {
                        $day[$key] += $value;
                    }
                }
                if ($full && in_array($record->absence_reason, [null, 'unexcused'], true)) {
                    $entry = $perStudent[$record->student_id] ?? ['class_id' => $round->class_id, 'unexcused' => 0, 'pending' => 0, 'dates' => []];
                    $entry[$record->absence_reason === null ? 'pending' : 'unexcused']++;
                    $entry['dates'][] = $date;
                    $perStudent[$record->student_id] = $entry;
                }
                if ($record->arrived_lesson !== null || $record->left_lesson !== null) {
                    $events[] = [
                        'date' => $date, 'class_id' => $round->class_id, 'student_id' => $record->student_id,
                        'arrived_lesson' => $record->arrived_lesson, 'left_lesson' => $record->left_lesson, 'leave_permission' => $record->leave_permission,
                    ];
                }
            }
            $byClass[$round->class_id] = $class;
            if ($day !== null) {
                $byDay[$date] = $day;
            }
        }

        $classRows = [];
        foreach ($classes as $class) {
            $classRows[] = $this->classRow($class, (int) ($rosterSizes[$class->id] ?? 0), $byClass[$class->id] ?? self::COUNTERS + ['surveyed' => []], $days);
        }
        [$students, $studentsTotal, $events] = $this->named($perStudent, $events, $classes, $anchor);

        $totals = self::COUNTERS;
        foreach ($classRows as $row) {
            foreach (array_keys(self::COUNTERS) as $key) {
                $totals[$key] += $row[$key];
            }
        }

        return [
            'type' => $type,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'school_days' => $days,
            'complete' => $this->isComplete($from, $to),
            'totals' => $totals + [
                'classes' => count($classRows),
                'students' => array_sum(array_column($classRows, 'students')),
                'class_days_expected' => count($classRows) * count($days),
                'class_days_surveyed' => array_sum(array_column($classRows, 'days_surveyed')),
                'attendance_pct' => self::percent($totals['marked'], $totals['absent_marked']),
            ],
            'days' => array_map(static fn (string $date, array $day): array => ['date' => $date, 'class_days_expected' => count($classRows)] + $day + ['attendance_pct' => self::percent($day['marked'], $day['absent_marked'])], array_keys($byDay), $byDay),
            'classes' => $classRows,
            'students' => $students,
            'students_total' => $studentsTotal,
            'events' => $events,
        ];
    }

    /** Қатысу пайызы (бір ондық таңба); белгіленген оқушы-күн жоқ болса null («дерек жоқ»). */
    public static function percent(int $marked, int $absentMarked): ?float
    {
        return $marked === 0 ? null : round(($marked - $absentMarked) * 100 / $marked, 1);
    }

    /**
     * Есептің аты-жөнсіз нұсқасы: Telegram-ға тек осы шығады (жобалық құжат, 9-бөлім).
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function withoutNames(array $report): array
    {
        $report['students'] = [];
        $report['events'] = [];
        $report['anonymous'] = true;

        return $report;
    }

    /**
     * Бір жазбаның санауыштарға қосатыны. Пайыздың негізі тек кезекші аралаған күннің жазбалары.
     *
     * @return array<string, int>
     */
    public function delta(object $record, bool $fullAbsence, bool $surveyed): array
    {
        $delta = [];
        if ($surveyed) {
            $delta['marked'] = 1;
        }
        if ($fullAbsence) {
            if ($surveyed) {
                $delta['absent_marked'] = 1;
            }
            $delta['absent'] = 1;
            $delta[$record->absence_reason ?? 'pending'] = 1;
        }
        if ($record->arrived_lesson !== null) {
            $delta['late'] = 1;
        }
        if ($record->left_lesson !== null) {
            $delta['left'] = 1;
            $delta['left_'.($record->leave_permission ?? 'pending')] = 1;
        }

        return $delta;
    }

    /**
     * Жолдардың санауыштарын қосады (апталар, параллельдер, жалпы жиын үшін).
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @return array<string, int|float|null>
     */
    public static function sum(iterable $rows): array
    {
        $total = self::COUNTERS;
        foreach ($rows as $row) {
            foreach (array_keys(self::COUNTERS) as $key) {
                $total[$key] += $row[$key];
            }
        }

        return $total + ['attendance_pct' => self::percent($total['marked'], $total['absent_marked'])];
    }

    /**
     * @param  array<string, mixed>  $counters
     * @param  list<string>  $days
     * @return array<string, mixed>
     */
    private function classRow(SchoolClass $class, int $students, array $counters, array $days): array
    {
        $surveyed = $counters['surveyed'];
        unset($counters['surveyed']);
        $missing = array_values(array_filter($days, static fn (string $d): bool => ! isset($surveyed[$d])));

        return [
            'id' => $class->id,
            'name' => $class->name(),
            'grade' => $class->grade,
            'shift' => $class->shift->value,
            'homeroom' => $class->homeroom?->full_name,
            'students' => $students,
            'days_expected' => count($days),
            'days_surveyed' => count($days) - count($missing),
            'missing_dates' => $missing,
        ] + $counters + ['attendance_pct' => self::percent($counters['marked'], $counters['absent_marked'])];
    }

    /**
     * Оқушылар мен оқиғаларға аты-жөн мен сынып атауын қосады.
     *
     * @param  array<int, array<string, mixed>>  $perStudent
     * @param  list<array<string, mixed>>  $events
     * @param  Collection<int, SchoolClass>  $classes
     * @return array{list<array<string, mixed>>, int, list<array<string, mixed>>}
     */
    private function named(array $perStudent, array $events, Collection $classes, CarbonImmutable $anchor): array
    {
        $classByStudent = array_column($events, 'class_id', 'student_id') + array_map(static fn (array $e): int => $e['class_id'], $perStudent);
        $students = Student::query()->whereIn('id', array_keys($classByStudent))->get()->keyBy('id');
        $labels = StudentNames::labels($students, $classByStudent, $anchor);
        $className = static fn (int $id): string => $classes->get($id)?->name() ?? '';
        $order = array_flip($classes->keys()->all());

        $rows = [];
        foreach ($perStudent as $id => $entry) {
            sort($entry['dates']);
            $rows[] = ['id' => $id, 'name' => $labels[$id] ?? '', 'class_id' => $entry['class_id'], 'class' => $className($entry['class_id'])] + $entry;
        }
        $rows = KazakhSort::sort($rows, static fn (array $r): array => [$r['name']]);
        // Себепсіз күні көп оқушы жоғарыда; тең болса анықталмағаны көбі; одан кейін әліпби реті сақталады.
        usort($rows, static fn (array $a, array $b): int => [$b['unexcused'], $b['pending']] <=> [$a['unexcused'], $a['pending']]);

        foreach ($events as &$event) {
            $event['name'] = $labels[$event['student_id']] ?? '';
            $event['class'] = $className($event['class_id']);
        }
        unset($event);
        $events = KazakhSort::sort($events, static fn (array $e): array => [$e['name']]);
        usort($events, static fn (array $a, array $b): int => [$a['date'], $order[$a['class_id']] ?? 0] <=> [$b['date'], $order[$b['class_id']] ?? 0]);

        return [array_slice($rows, 0, self::STUDENTS_LIMIT), count($rows), $events];
    }
}
