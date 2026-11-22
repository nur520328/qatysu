<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Telegram-ға жіберілетін не жіберілген хабар. Мәтінде оқушының аты-жөні болмайды.
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $dedupe_key
 * @property string $text
 * @property int|null $report_id
 * @property string $status
 * @property int $attempts
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'type', 'dedupe_key', 'text', 'report_id', 'status', 'attempts', 'error', 'sent_at'])]
class NotificationLog extends Model
{
    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $table = 'notification_log';

    protected function casts(): array
    {
        return ['sent_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
