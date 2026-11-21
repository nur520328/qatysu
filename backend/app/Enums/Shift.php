<?php

namespace App\Enums;

/** Смена сыныптың қасиеті: әр сынып бір ғана сменада оқиды. */
enum Shift: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
