<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property bool $is_current
 */
#[Fillable(['name', 'start_date', 'end_date', 'is_current'])]
class AcademicYear extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date', 'is_current' => 'boolean'];
    }

    /** @return HasMany<CalendarDay, $this> */
    public function days(): HasMany
    {
        return $this->hasMany(CalendarDay::class);
    }

    /** @return HasMany<SchoolClass, $this> */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    public static function current(): ?self
    {
        return self::query()->where('is_current', true)->first();
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'is_current' => $this->is_current,
        ];
    }
}
