<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Есептерді бекіту: кезең аяқталғанда сол сәттегі сурет `reports` кестесіне жазылады және кейін өзгермейді
 * (жобалық құжат 5.3-бөлімнің 7-тармағы; docs/кезең-8-жоспар.md, 6 және 7-шешім).
 */
class ReportSnapshotService
{
    public function __construct(private readonly WeeklyReportService $weekly) {}

    public function weekly(CarbonImmutable $monday): ?Report
    {
        return Report::query()->where('type', Report::WEEKLY)->whereDate('period_start', $monday->toDateString())->first();
    }

    /**
     * Бекітілуі тиіс, бірақ әлі бекітілмеген апталық есептерді жасайды: ағымдағы апта аяқталса соны,
     * өткен аптаның суреті жоқ болса оны да (жоспарлағыш бір күн істемей қалса да есеп жоғалмайды).
     *
     * @return list<Report>
     */
    public function freezeDueWeekly(): array
    {
        $current = WeeklyReportService::weekStart(CarbonImmutable::today());
        $created = [];
        foreach ([$current->subWeek(), $current] as $monday) {
            if ($this->weekly($monday) !== null || ! $this->weekly->isComplete($monday) || $this->weekly->elapsedSchoolDays($monday) === []) {
                continue;
            }
            try {
                $created[] = Report::query()->create([
                    'type' => Report::WEEKLY,
                    'period_start' => $monday->toDateString(),
                    'period_end' => $monday->addDays(6)->toDateString(),
                    'generated_at' => now(),
                    'data' => $this->weekly->compute($monday),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Қатар іске қосылған екінші тексеріс: есеп бекітіліп қойған.
            }
        }

        return $created;
    }

    /** Есеп бекітілгеннен кейін оның кезеңінде қанша қатысу жазбасы қосылды не өзгерді. */
    public function changedSince(Report $report): int
    {
        return AttendanceRecord::query()
            ->where('updated_at', '>', $report->generated_at)
            ->whereHas('round', fn ($q) => $q->whereDate('date', '>=', $report->period_start->toDateString())
                ->whereDate('date', '<=', $report->period_end->toDateString()))
            ->count();
    }
}
