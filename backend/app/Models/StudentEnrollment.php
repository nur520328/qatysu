<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $student_id
 * @property int $class_id
 * @property Carbon $start_date
 * @property Carbon|null $end_date
 * @property-read Student $student
 * @property-read SchoolClass $schoolClass
 */
#[Fillable(['student_id', 'class_id', 'start_date', 'end_date'])]
class StudentEnrollment extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<SchoolClass, $this> */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * Көрсетілген күні күшінде болған жазбалар (екі шеті де қоса есептеледі).
     *
     * @param  Builder<self>  $query
     */
    public function scopeActiveOn(Builder $query, Carbon|string $date): void
    {
        $day = Carbon::parse($date)->toDateString();
        $query->whereDate('start_date', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $day));
    }
}
