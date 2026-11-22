<?php

namespace App\Services\Attendance;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Support\KazakhSort;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Өткен күннің не кезеңнің сыныптары: жиынтық пен есептер бір ережені қолданады.
 * Сыныптар сол күн кіретін оқу жылынан алынады; жабылған сынып сол кезеңде жазбасы болса ғана кіреді.
 */
class ClassScope
{
    /**
     * @param  list<int>  $classIdsWithRounds
     * @return Collection<int, SchoolClass> сынып id бойынша, параллель мен әріп ретімен
     */
    public function forDay(CarbonImmutable $date, array $classIdsWithRounds): Collection
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
}
