<?php

namespace App\Enums;

/** Сыныптың оқу тілі (интерфейс тілінен бөлек ұғым). */
enum ClassLanguage: string
{
    case Kazakh = 'kk';
    case Russian = 'ru';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
