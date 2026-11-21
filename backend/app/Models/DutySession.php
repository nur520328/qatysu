<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Бір планшеттің бір кодпен кіруі.
 *
 * @property int $id
 * @property int $duty_code_id
 * @property int $device_number
 * @property Carbon $last_seen_at
 * @property Carbon|null $ended_at
 * @property-read DutyCode $code
 */
#[Fillable(['duty_code_id', 'device_number', 'ip', 'user_agent', 'last_seen_at', 'ended_at'])]
class DutySession extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    /** @return BelongsTo<DutyCode, $this> */
    public function code(): BelongsTo
    {
        return $this->belongsTo(DutyCode::class, 'duty_code_id');
    }
}
