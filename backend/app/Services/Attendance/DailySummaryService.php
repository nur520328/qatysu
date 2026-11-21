<?php

namespace App\Services\Attendance;

use App\Enums\Shift;
use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\AttendanceRoundConflict;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\CalendarService;
use App\Services\Duty\DutyCodeService;
use App\Support\KazakhSort;
use App\Support\StudentNames;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Завучтың күнделікті жиынтығы: бір күннің смена мен сынып бойынша қатысу көрінісі.
 * Ештеңе сақталмайды, әр жолы жазбалардан есептеледі (docs/кезең-7-жоспар.md, 1-шешім).
 * Оқушының күйі `AttendanceRecord` ережелерімен анықталады.
 */
class DailySummaryService
{
    public function __construct(
        private readonly CalendarService $calendar,
        private readonly DutyCodeService $codes,
    ) {}

    /** @return array<string, mixed> */
    public function forDate(CarbonImmutable $date): array
    {
        $date = $date->startOfDay();
        $day = $date->toDateString();
        $today = CarbonImmutable::today();

        $rounds = AttendanceRound::query()->whereDate('date', $day)->get()->keyBy('class_id');
        $classes = $this->classes($date, $rounds->keys()->all());
        $rosterSizes = StudentEnrollment::query()->whereIn('class_id', $classes->keys())->activeOn($date)
            ->selectRaw('class_id, count(*) as total')->groupBy('class_id')->pluck('total', 'class_id');
        $records = AttendanceRecord::query()->whereIn('round_id', $rounds->pluck('id'))->get()->groupBy('round_id');

        // Аты-жөні тек назар аударуды қажет ететін оқушыларға керек (жоқ, кешіккен, кеткен).
        $notable = $records->flatten()->filter(static fn (AttendanceRecord $r): bool => self::isNotable($r));
        $students = Student::query()->whereIn('id', $notable->pluck('student_id'))->get()->keyBy('id');
        $classByRound = $rounds->pluck('class_id', 'id');
        $labels = StudentNames::labels(
            $students,
            $notable->mapWithKeys(static fn (AttendanceRecord $r): array => [$r->student_id => $classByRound[$r->round_id]])->all(),
            $date,
        );

        $rows = [];
        foreach ($classes as $class) {
            $round = $rounds->get($class->id);
            $rows[$class->shift->value][] = $this->classRow(
                $class,
                (int) ($rosterSizes[$class->id] ?? 0),
                $round,
                $round === null ? collect() : ($records->get($round->id) ?? collect()),
                $labels,
            );
        }

        return [
            'date' => $day,
            'is_today' => $date->equalTo($today),
            'is_school_day' => $this->calendar->isSchoolDay($date),
            'previous_school_day' => $this->calendar->previousSchoolDay($date)?->toDateString(),
            // Болашақ күн ашылмайды, сондықтан «келесі» бүгіннен аспайды.
            'next_school_day' => $this->nextUpTo($date, $today)?->toDateString(),
            'open_conflicts' => AttendanceRoundConflict::query()->whereDate('date', $day)->whereNull('resolved_at')->count(),
            'shifts' => array_map(fn (Shift $shift): array => $this->shiftBlock($date, $shift, $rows[$shift->value] ?? []), Shift::cases()),
        ];
    }

    /**
     * Сол күн кіретін оқу жылының сыныптары. Жабылған сынып сол күні жазбасы болса ғана кіреді.
     *
     * @param  list<int>  $classIdsWithRounds
     * @return Collection<int, SchoolClass>
     */
    private function classes(CarbonImmutable $date, array $classIdsWithRounds): Collection
    {
        $year = AcademicYear::query()->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())->orderByDesc('is_current')->first();
        if ($year === null) {
            return collect();
        }
        $classes = SchoolClass::query()->where('academic_year_id', $year->id)
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $classIdsWithRounds))
            ->with('homeroom')->get();

        return collect(KazakhSort::sort($classes, static fn (SchoolClass $c): array => [sprintf('%02d', $c->grade), $c->letter]))->keyBy('id');
    }

    /**
     * @param  Collection<int, AttendanceRecord>  $records
     * @param  array<int, string>  $labels
     * @return array<string, mixed>
     */
    private function classRow(SchoolClass $class, int $rosterSize, ?AttendanceRound $round, Collection $records, array $labels): array
    {
        $surveyed = $round !== null && $round->duty_done;
        $people = [];
        $absent = $late = $left = $pending = 0;
        foreach ($records as $record) {
            if (! self::isNotable($record)) {
                continue;
            }
            $absent += $record->isFullAbsence() ? 1 : 0;
            $late += $record->arrived_lesson !== null ? 1 : 0;
            $left += $record->left_lesson !== null ? 1 : 0;
            $pending += $record->pendingKind() !== null ? 1 : 0;
            $people[] = [
                'id' => $record->student_id,
                'name' => $labels[$record->student_id] ?? '',
                'round_status' => $record->round_status->value,
                'full_absence' => $record->isFullAbsence(),
                'arrived_lesson' => $record->arrived_lesson,
                'left_lesson' => $record->left_lesson,
                'absence_reason' => $record->absence_reason,
                'leave_permission' => $record->leave_permission,
                'note' => $record->note,
                'pending' => $record->pendingKind(),
            ];
        }

        return [
            'id' => $class->id,
            'name' => $class->name(),
            'homeroom' => $class->homeroom?->full_name,
            'students' => $rosterSize,
            'surveyed' => $surveyed,
            'saved_at' => $surveyed ? $round->created_at->toIso8601String() : null,
            'received_late' => $surveyed && $round->received_late,
            // Аралынған сыныпта әр оқушының жазбасы бар; аралынбағанда тек мұғалім не жетекші тіркегендері.
            'unmarked' => max(0, $rosterSize - $records->count()),
            'absent' => $absent,
            'late' => $late,
            'left' => $left,
            'pending' => $pending,
            'people' => KazakhSort::sort($people, static fn (array $p): array => [$p['name']]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $classes
     * @return array<string, mixed>
     */
    private function shiftBlock(CarbonImmutable $date, Shift $shift, array $classes): array
    {
        $people = array_merge(...array_column($classes, 'people'), ...[[]]);
        $fullAbsences = array_filter($people, static fn (array $p): bool => $p['full_absence']);
        $leavers = array_filter($people, static fn (array $p): bool => $p['left_lesson'] !== null);
        $count = static fn (array $rows, string $field, ?string $value): int => count(array_filter($rows, static fn (array $p): bool => $p[$field] === $value));

        return [
            'shift' => $shift->value,
            'ended' => $this->codes->shiftEnd($date, $shift)->isPast(),
            'classes_total' => count($classes),
            'classes_surveyed' => count(array_filter($classes, static fn (array $c): bool => $c['surveyed'])),
            'students' => array_sum(array_column($classes, 'students')),
            'unmarked' => array_sum(array_column($classes, 'unmarked')),
            'absent' => count($fullAbsences),
            'absent_by_reason' => [
                'sick' => $count($fullAbsences, 'absence_reason', 'sick'),
                'excused' => $count($fullAbsences, 'absence_reason', 'excused'),
                'unexcused' => $count($fullAbsences, 'absence_reason', 'unexcused'),
                'pending' => $count($fullAbsences, 'absence_reason', null),
            ],
            'late' => array_sum(array_column($classes, 'late')),
            'left' => count($leavers),
            'left_by_permission' => [
                'permitted' => $count($leavers, 'leave_permission', 'permitted'),
                'unpermitted' => $count($leavers, 'leave_permission', 'unpermitted'),
                'pending' => $count($leavers, 'leave_permission', null),
            ],
            'classes' => $classes,
        ];
    }

    private function nextUpTo(CarbonImmutable $date, CarbonImmutable $limit): ?CarbonImmutable
    {
        $next = $this->calendar->nextSchoolDay($date);

        return $next !== null && $next->lessThanOrEqualTo($limit) ? $next : null;
    }

    private static function isNotable(AttendanceRecord $record): bool
    {
        return $record->isFullAbsence() || $record->arrived_lesson !== null || $record->left_lesson !== null;
    }
}
