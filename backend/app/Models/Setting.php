<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Кодқа тимей өзгертілетін баптаулар (сабақ саны, еске салу мерзімі т.б.).
 *
 * @property string $key
 * @property string $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** Әдепкі мәндер мен рұқсат етілген шектер. Тізімде жоқ кілт қабылданбайды. */
    public const DEFAULTS = [
        // Бір сменадағы ең көп сабақ саны (кешігу мен кетіп қалуды тіркегенде таңдалады).
        'max_lessons_per_shift' => ['default' => 7, 'min' => 1, 'max' => 12],
        // Жетекші өткен күннің жазбасын неше күнге дейін өзгерте алады.
        'homeroom_edit_days' => ['default' => 14, 'min' => 0, 'max' => 90],
        // Анықталмаған себеп неше оқу күнінен кейін есепте бөлек көрсетіледі.
        'pending_reason_days' => ['default' => 3, 'min' => 1, 'max' => 30],
        // Смена соңы, түн ортасынан бастап минутпен (790 = 13:10; мектептің кестесі: 13:10 және 18:40). Кезекші коды осы уақытта жарамсыз болады.
        'morning_shift_end' => ['default' => 790, 'min' => 600, 'max' => 1020],
        'afternoon_shift_end' => ['default' => 1120, 'min' => 780, 'max' => 1380],
    ];

    public static function int(string $key): int
    {
        $stored = self::query()->find($key)?->value;

        return $stored === null ? self::DEFAULTS[$key]['default'] : (int) $stored;
    }

    /** @return array<string, int> */
    public static function allInts(): array
    {
        $stored = self::query()->pluck('value', 'key');

        return array_map(
            static fn (string $key): int => isset($stored[$key]) ? (int) $stored[$key] : self::DEFAULTS[$key]['default'],
            array_combine(array_keys(self::DEFAULTS), array_keys(self::DEFAULTS)),
        );
    }
}
