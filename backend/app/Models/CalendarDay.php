<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $academic_year_id
 * @property Carbon $date
 * @property bool $is_school_day
 * @property string|null $note
 */
#[Fillable(['academic_year_id', 'date', 'is_school_day', 'note'])]
class CalendarDay extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date', 'is_school_day' => 'boolean'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'date' => $this->date->toDateString(),
            'is_school_day' => $this->is_school_day,
            'note' => $this->note,
        ];
    }
}
