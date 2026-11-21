<?php

namespace App\Support;

/**
 * Бір реттік пароль: адам экраннан қатесіз көшіре алатындай, шатасатын таңбаларсыз
 * (0/O, 1/l/I) кіші латын әріптері мен сандар, төрт-төрттен сызықшамен бөлінген.
 * Мысалы: `kq7m-x3wd-9fhp`. Энтропиясы шамамен 59 бит; оны иесі алғаш кіргенде ауыстырады.
 */
final class OneTimePassword
{
    private const LETTERS = 'abcdefghjkmnpqrstuvwxyz';

    private const DIGITS = '23456789';

    public static function generate(): string
    {
        $alphabet = self::LETTERS.self::DIGITS;

        do {
            $chars = '';
            for ($i = 0; $i < 12; $i++) {
                $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $password = implode('-', str_split($chars, 4));
        } while (! PasswordPolicy::isStrong($password));

        return $password;
    }
}
