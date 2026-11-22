<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/**
 * Айлық есеп: күнтізбелік ай. Апталық есептің бәрі + апталар бойынша динамика және параллельдер салыстыруы
 * (docs/кезең-9-жоспар.md, 3-шешім). Есептеу ережесі `PeriodReport`-та.
 */
class MonthlyReportService
{
    public function __construct(private readonly PeriodReport $period) {}

    public static function monthStart(CarbonImmutable $date): CarbonImmutable
    {
        return $date->startOfDay()->startOfMonth();
    }

    /** @return list<string> */
    public function elapsedSchoolDays(CarbonImmutable $first): array
    {
        return $this->period->elapsedSchoolDays($first, $first->endOfMonth()->startOfDay());
    }

    public function isComplete(CarbonImmutable $first): bool
    {
        return $this->period->isComplete($first, $first->endOfMonth()->startOfDay());
    }

    /** @return array<string, mixed> */
    public function compute(CarbonImmutable $first): array
    {
        $first = self::monthStart($first);
        $report = $this->period->compute($first, $first->endOfMonth()->startOfDay(), 'monthly');

        // Апталар: ай ішіндегі дүйсенбі–жексенбі аралықтары; ай шекарасында апта қиылады.
        $weeks = [];
        foreach ($report['days'] as $day) {
            $monday = WeeklyReportService::weekStart(CarbonImmutable::parse($day['date']))->toDateString();
            $weeks[$monday][] = $day;
        }
        $report['weeks'] = array_values(array_map(static fn (array $days): array => [
            'from' => $days[0]['date'],
            'to' => end($days)['date'],
            'school_days' => count($days),
            'class_days_expected' => array_sum(array_column($days, 'class_days_expected')),
            'class_days_surveyed' => array_sum(array_column($days, 'class_days_surveyed')),
        ] + PeriodReport::sum($days), $weeks));

        $grades = [];
        foreach ($report['classes'] as $class) {
            $grades[$class['grade']][] = $class;
        }
        ksort($grades);
        $report['grades'] = array_values(array_map(static fn (int $grade, array $classes): array => [
            'grade' => $grade,
            'classes' => count($classes),
            'students' => array_sum(array_column($classes, 'students')),
            'class_days_expected' => array_sum(array_column($classes, 'days_expected')),
            'class_days_surveyed' => array_sum(array_column($classes, 'days_surveyed')),
        ] + PeriodReport::sum($classes), array_keys($grades), $grades));

        return $report;
    }
}
