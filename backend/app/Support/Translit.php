<?php

namespace App\Support;

/** Қазақ және орыс кирилл әріптерін логинге жарайтын латын әріптеріне айналдырады. */
final class Translit
{
    private const MAP = [
        'а' => 'a', 'ә' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ғ' => 'g', 'д' => 'd', 'е' => 'e',
        'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'i', 'к' => 'k', 'қ' => 'k', 'л' => 'l',
        'м' => 'm', 'н' => 'n', 'ң' => 'n', 'о' => 'o', 'ө' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
        'т' => 't', 'у' => 'u', 'ұ' => 'u', 'ү' => 'u', 'ф' => 'f', 'х' => 'kh', 'һ' => 'h', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sh', 'ъ' => '', 'ы' => 'y', 'і' => 'i', 'ь' => '', 'э' => 'e',
        'ю' => 'yu', 'я' => 'ya',
    ];

    /** Тек кіші латын әріптері мен сандар қалады; басқа таңбалар алынады. */
    public static function toLatin(string $text): string
    {
        $out = '';
        foreach (mb_str_split(mb_strtolower($text)) as $char) {
            $out .= self::MAP[$char] ?? (preg_match('/\A[a-z0-9]\z/', $char) === 1 ? $char : '');
        }

        return $out;
    }

    /** Логин негізі: тегі + нүкте + атының бірінші әрпі, мысалы `akhmetova.g`. */
    public static function loginBase(string $lastName, string $firstName): string
    {
        $last = self::toLatin($lastName);
        $initial = substr(self::toLatin($firstName), 0, 1);

        return $initial === '' ? $last : $last.'.'.$initial;
    }
}
