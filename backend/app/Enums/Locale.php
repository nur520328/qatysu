<?php

namespace App\Enums;

/** Интерфейс тілдері. Тізім frontend-тегі `src/i18n/index.ts` файлымен бірдей болуы тиіс. */
enum Locale: string
{
    case Kazakh = 'kk';
    case Russian = 'ru';
    case Chinese = 'zh';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
