<?php

namespace App\Models;

use App\Enums\Shift;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Бір күнге, бір сменаға арналған кезекші коды. Жолдар өшірілмейді: аралаулар оларға сілтейді.
 *
 * @property int $id
 * @property Carbon $date
 * @property Shift $shift
 * @property string $code_hash
 * @property int $issued_by
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property-read User $issuer
 */
#[Fillable(['date', 'shift', 'code_hash', 'issued_by', 'expires_at', 'revoked_at'])]
class DutyCode extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date', 'shift' => Shift::class, 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return HasMany<DutySession, $this> */
    public function sessions(): HasMany
    {
        return $this->hasMany(DutySession::class);
    }

    /** Код дәл қазір кіруге және белгілеуге жарай ма. */
    public function isUsableNow(): bool
    {
        return $this->revoked_at === null && $this->date->isToday() && $this->expires_at->isFuture();
    }
}
