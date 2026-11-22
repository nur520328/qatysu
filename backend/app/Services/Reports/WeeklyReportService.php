<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/** Апталық есеп: дүйсенбі–жексенбі (docs/кезең-8-жоспар.md). Есептеу ережесі `PeriodReport`-та. */
class WeeklyReportService
{
    public function __construct(private readonly PeriodReport $period) {}

    /** Күн кіретін аптаның дүйсенбісі. */
    public static function weekStart(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfDay()->startOfWeek(CarbonImmutable::MONDAY);
    }

    /** @return list<string> */
    public function elapsedSchoolDays(CarbonImmutable $monday): array
    {
        return $this->period->elapsedSchoolDays($monday, $monday->addDays(6));
    }

    public function isComplete(CarbonImmutable $monday): bool
    {
        return $this->period->isComplete($monday, $monday->addDays(6));
    }

    /** @return array<string, mixed> */
    public function compute(CarbonImmutable $monday): array
    {
        $monday = self::weekStart($monday);

        return $this->period->compute($monday, $monday->addDays(6), 'weekly');
    }
}
