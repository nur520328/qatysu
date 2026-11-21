<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Жазбаның әр өрісінің әр өзгерісі. Тек қосылады, өзгертілмейді және өшірілмейді. */
#[Table('attendance_audit')]
#[Fillable(['record_id', 'field', 'old_value', 'new_value', 'changed_by', 'duty_session_id', 'changed_role', 'changed_at'])]
class AttendanceAudit extends Model
{
    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['changed_at' => 'datetime'];
    }
}
