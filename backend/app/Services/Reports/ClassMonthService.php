<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Models\StudentEnrollment;
use App\Support\KazakhSort;
use App\Support\StudentNames;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Сынып жетекшісінің айлық қорытындысы: бір сыныптың әр оқушысы бойынша (docs/кезең-9-жоспар.md, 5–7-шешім).
 * Әрқашан қазіргі күй: бекітілмейді. Санау ережесі `PeriodReport`-пен бірдей (сол санауыштар, сол пайыз).
 */
class ClassMonthService
{
    public function __construct(private readonly PeriodReport $period) {}

    /** @return array<string, mixed> */
    public function compute(SchoolClass $class, CarbonImmutable $first): array
    {
        $first = MonthlyReportService::monthStart($first);
        $last = $first->endOfMonth()->startOfDay();
        $today = CarbonImmutable::today();
        $days = $this->period->elapsedSchoolDays($first, $last);
        $until = min($last->toDateString(), $today->toDateString());

        // Ай ішінде осы сыныпта болған барлық оқушы (кейін ауысқан не шыққандары да).
        $enrollments = StudentEnrollment::query()->where('class_id', $class->id)->with('student')
            ->whereDate('start_date', '<=', $until)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $first->toDateString()))
            ->get();
        $students = $enrollments->pluck('student')->unique('id')->keyBy('id');
        $inClassNow = $enrollments->filter(static fn (StudentEnrollment $e): bool => $e->end_date === null || $e->end_date->toDateString() >= $until)
            ->pluck('student_id')->flip();

        $rounds = DB::table('attendance_rounds')->where('class_id', $class->id)
            ->whereDate('date', '>=', $first->toDateString())->whereDate('date', '<=', $last->toDateString())
            ->get(['id', 'date', 'duty_done'])->keyBy('id');
        $surveyed = [];
        foreach ($rounds as $round) {
            if ($round->duty_done) {
                $surveyed[CarbonImmutable::parse($round->date)->toDateString()] = true;
            }
        }
        $records = DB::table('attendance_records')->whereIn('round_id', $rounds->keys())
            ->get(['round_id', 'student_id', 'round_status', 'arrived_lesson', 'left_lesson', 'absence_reason', 'leave_permission']);

        $rows = [];
        foreach ($students as $id => $student) {
            $rows[$id] = PeriodReport::COUNTERS + ['dates' => []];
        }
        foreach ($records as $record) {
            if (! isset($rows[$record->student_id])) {
                continue;
            }
            $round = $rounds[$record->round_id];
            $full = AttendanceRecord::fullAbsence($record->round_status, $record->arrived_lesson, $record->left_lesson);
            foreach ($this->period->delta($record, $full, (bool) $round->duty_done) as $key => $value) {
                $rows[$record->student_id][$key] += $value;
            }
            if ($full) {
                $rows[$record->student_id]['dates'][] = CarbonImmutable::parse($round->date)->toDateString();
            }
        }

        $anchor = CarbonImmutable::parse($days === [] ? $until : end($days));
        $labels = StudentNames::labels($students, array_fill_keys($students->keys()->all(), $class->id), $anchor);
        $list = [];
        foreach ($rows as $id => $row) {
            sort($row['dates']);
            $list[] = ['id' => $id, 'name' => $labels[$id] ?? '', 'in_class_now' => isset($inClassNow[$id])]
                + $row + ['attendance_pct' => PeriodReport::percent($row['marked'], $row['absent_marked'])];
        }
        $missing = array_values(array_filter($days, static fn (string $d): bool => ! isset($surveyed[$d])));
        $current = MonthlyReportService::monthStart($today);

        return [
            'class' => ['id' => $class->id, 'name' => $class->name()],
            'month' => [
                'from' => $first->toDateString(),
                'to' => $last->toDateString(),
                'previous' => $first->subMonthNoOverflow()->toDateString(),
                'next' => $first->lessThan($current) ? $first->addMonthNoOverflow()->toDateString() : null,
                'is_current' => $first->equalTo($current),
            ],
            'complete' => $this->period->isComplete($first, $last),
            'days_expected' => count($days),
            'days_surveyed' => count($days) - count($missing),
            'missing_dates' => $missing,
            'totals' => PeriodReport::sum($list),
            'students' => KazakhSort::sort($list, static fn (array $r): array => [$r['name']]),
        ];
    }
}
