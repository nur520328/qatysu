<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\CalendarDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;

/** Оқу күнтізбесі: қай күні сабақ бар, қай күні жоқ. */
class CalendarService
{
    /**
     * Оқу жылының әр күніне жол жасайды. Оқу аптасы 5 күн: дүйсенбі–жұма оқу күні,
     * сенбі мен жексенбі демалыс. Каникул мен мерекені әкімші кейін белгілейді.
     */
    public function generate(AcademicYear $year): void
    {
        $rows = [];
        foreach (CarbonPeriod::create($year->start_date, $year->end_date) as $date) {
            $rows[] = [
                'academic_year_id' => $year->id,
                'date' => $date->toDateString(),
                'is_school_day' => $date->isWeekday(),
                'note' => null,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            CalendarDay::query()->insert($chunk);
        }
    }

    /** Күндер аралығын оқу күні не оқу күні емес деп белгілейді. Өзгерген күндер санын қайтарады. */
    public function setRange(AcademicYear $year, CarbonImmutable $from, CarbonImmutable $to, bool $isSchoolDay, ?string $note): int
    {
        return CalendarDay::query()
            ->where('academic_year_id', $year->id)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->update(['is_school_day' => $isSchoolDay, 'note' => $note]);
    }

    /** Күнтізбеде жоқ күн (оқу жылынан тыс) оқу күні болып саналмайды. */
    public function isSchoolDay(CarbonImmutable|string $date): bool
    {
        return CalendarDay::query()
            ->whereDate('date', CarbonImmutable::parse($date)->toDateString())
            ->where('is_school_day', true)
            ->exists();
    }

    /** Көрсетілген күннен кейінгі бірінші оқу күні (күнтізбеде жоқ болса null). */
    public function nextSchoolDay(CarbonImmutable $after): ?CarbonImmutable
    {
        $date = CalendarDay::query()
            ->whereDate('date', '>', $after->toDateString())
            ->where('is_school_day', true)
            ->orderBy('date')
            ->value('date');

        return $date === null ? null : CarbonImmutable::parse($date)->startOfDay();
    }

    /** Көрсетілген күннің алдындағы соңғы оқу күні (күнтізбеде жоқ болса null). */
    public function previousSchoolDay(CarbonImmutable $before): ?CarbonImmutable
    {
        $date = CalendarDay::query()
            ->whereDate('date', '<', $before->toDateString())
            ->where('is_school_day', true)
            ->orderByDesc('date')
            ->value('date');

        return $date === null ? null : CarbonImmutable::parse($date)->startOfDay();
    }
}
