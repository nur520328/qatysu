<?php

namespace Database\Factories;

use App\Enums\Locale;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Тек жасанды дерек: нақты мұғалім аты қолданылмайды.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public const DEFAULT_PASSWORD = 'synaq-parol-1';

    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'full_name' => 'Сынақ Мұғалім '.fake()->unique()->numberBetween(1, 99999),
            'login' => 'synaq'.fake()->unique()->numberBetween(1, 99999),
            'password' => static::$password ??= Hash::make(self::DEFAULT_PASSWORD),
            'role' => Role::Teacher,
            'locale' => Locale::Kazakh,
            'must_change_password' => false,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin]);
    }

    public function mustChangePassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
