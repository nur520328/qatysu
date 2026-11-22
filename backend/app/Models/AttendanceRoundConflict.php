<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Аралауы бар сыныпқа басқа планшеттен келген жеке аралау. Үстінен жазылмайды, жойылмайды:
 * завуч шешкенше осында сақталады (шешу экраны 5-кезеңде).
 *
 * @property list<int> $absent_student_ids
 */
#[Fillable(['class_id', 'date', 'duty_code_id', 'duty_session_id', 'client_uuid', 'client_time', 'absent_student_ids', 'resolved_at', 'resolved_by', 'resolution'])]
class AttendanceRoundConflict extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date', 'client_time' => 'datetime', 'absent_student_ids' => 'array', 'resolved_at' => 'datetime'];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }
}
