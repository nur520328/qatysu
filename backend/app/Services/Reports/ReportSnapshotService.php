<?php

namespace App\Services\Reports;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\Report;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Есептерді бекіту: кезең аяқталғанда сол сәттегі сурет `reports` кестесіне жазылады және кейін өзгермейді
 * (жобалық құжат 5.3-бөлімнің 7-тармағы; docs/кезең-8-жоспар.md, 6 және 7-шешім).
 */
class ReportSnapshotService
{
    public function __construct(
        private readonly WeeklyReportService $weekly,
        private readonly MonthlyReportService $monthly,
    ) {}

    public function find(string $type, CarbonImmutable $start): ?Report
    {
        return Report::query()->where('type', $type)->whereDate('period_start', $start->toDateString())->first();
    }

    /**
     * Бекітілуі тиіс, бірақ әлі бекітілмеген есептерді жасайды: ағымдағы апта не ай аяқталса соны,
     * өткен кезеңнің суреті жоқ болса оны да (жоспарлағыш бір күн істемей қалса да есеп жоғалмайды).
     * Жазбасы мүлде жоқ кезең бекітілмейді.
     *
     * @return list<Report>
     */
    public function freezeDue(): array
    {
        $today = CarbonImmutable::today();
        $week = WeeklyReportService::weekStart($today);
        $month = MonthlyReportService::monthStart($today);
        $candidates = [
            [Report::WEEKLY, $week->subWeek(), $this->weekly],
            [Report::WEEKLY, $week, $this->weekly],
            [Report::MONTHLY, $month->subMonthNoOverflow(), $this->monthly],
            [Report::MONTHLY, $month, $this->monthly],
        ];

        $created = [];
        foreach ($candidates as [$type, $start, $service]) {
            $end = $type === Report::WEEKLY ? $start->addDays(6) : $start->endOfMonth()->startOfDay();
            if ($this->find($type, $start) !== null || ! $service->isComplete($start) || $service->elapsedSchoolDays($start) === []) {
                continue;
            }
            // Бірде-бір жазбасы жоқ кезеңде сақтайтын сурет жоқ (платформа іске қосылғанға дейінгі кезең, каникул).
            $hasRounds = AttendanceRound::query()->whereDate('date', '>=', $start->toDateString())
                ->whereDate('date', '<=', $end->toDateString())->exists();
            if (! $hasRounds) {
                continue;
            }
            try {
                $created[] = Report::query()->create([
                    'type' => $type,
                    'period_start' => $start->toDateString(),
                    'period_end' => $end->toDateString(),
                    'generated_at' => now(),
                    'data' => $service->compute($start),
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
