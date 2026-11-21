<?php

namespace Tests\Unit;

use App\Support\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Осы жағдайлар frontend-тегі `src/test/app.test.tsx` файлындағы «пароль ережесі» тестімен бірдей. */
class PasswordPolicyTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function cases(): array
    {
        return [
            'латын әріп пен сан' => ['zhanaparol9', true],
            'қазақ әріптері мен сан' => ['құпиясөз2026', true],
            'тым қысқа' => ['qysqa1', false],
            'тек әріп' => ['tekaripter', false],
            'тек сан' => ['1234567890', false],
            'дәл 8 таңба' => ['abcdefg1', true],
            '7 таңба' => ['abcdef1', false],
        ];
    }

    #[DataProvider('cases')]
    public function test_policy(string $password, bool $expected): void
    {
        $this->assertSame($expected, PasswordPolicy::isStrong($password));
    }
}
