<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Бекітілген есеп: кезең аяқталған сәттегі сурет. Кейін жазбалар өзгерсе де өзгермейді.
 *
 * @property int $id
 * @property string $type
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property CarbonImmutable $generated_at
 * @property array<string, mixed> $data
 */
#[Fillable(['type', 'period_start', 'period_end', 'generated_at', 'data'])]
class Report extends Model
{
    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    protected function casts(): array
    {
        return [
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
            'generated_at' => 'immutable_datetime',
            'data' => 'array',
        ];
    }
}
