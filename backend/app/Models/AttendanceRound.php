<?php

namespace App\Models;

use App\Enums\Shift;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Бір сыныптың бір күнгі аралауы.
 *
 * @property int $id
 * @property int $class_id
 * @property Carbon $date
 * @property Shift $shift
 * @property int|null $duty_code_id
 * @property int|null $duty_session_id
 * @property string $client_uuid
 * @property Carbon|null $client_time
 * @property int $revision
 * @property bool $received_late
 * @property bool $duty_done кезекші сыныпты аралады ма (false: жазбаны мұғалім не жетекші ашқан)
 * @property Carbon $updated_at
 */
#[Fillable(['class_id', 'date', 'shift', 'duty_code_id', 'duty_session_id', 'client_uuid', 'client_time', 'revision', 'received_late', 'duty_done'])]
class AttendanceRound extends Model
{
    protected $attributes = ['revision' => 1, 'received_late' => false, 'duty_done' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date', 'shift' => Shift::class, 'client_time' => 'datetime', 'revision' => 'integer', 'received_late' => 'boolean', 'duty_done' => 'boolean'];
    }

    /** @return HasMany<AttendanceRecord, $this> */
    public function records(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class, 'round_id');
    }
}
