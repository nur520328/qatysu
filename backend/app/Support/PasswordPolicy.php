<?php

namespace App\Support;

/** Пароль ережесі. Frontend-тегі `src/auth/password.ts` дәл осы ережені қайталайды. */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;

    public static function isStrong(string $password): bool
    {
        return mb_strlen($password) >= self::MIN_LENGTH
            && preg_match('/\p{L}/u', $password) === 1
            && preg_match('/\p{N}/u', $password) === 1;
    }
}
