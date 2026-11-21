<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\Role;
use App\Support\KazakhSort;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $id
 * @property string $full_name
 * @property string $login
 * @property string $password
 * @property Role $role
 * @property Locale $locale
 * @property bool $must_change_password
 * @property bool $is_active
 * @property string|null $telegram_chat_id
 */
#[Fillable(['full_name', 'login', 'password', 'role', 'locale', 'must_change_password', 'is_active'])]
#[Hidden(['password', 'telegram_chat_id'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** Дерекқордағы әдепкі мәндермен бірдей. */
    protected $attributes = ['locale' => 'kk', 'must_change_password' => true, 'is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'locale' => Locale::class,
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Ағымдағы оқу жылында жетекшілік ететін ашық сыныптардың атаулары.
     *
     * @return list<string>
     */
    public function homeroomClassNames(): array
    {
        $classes = SchoolClass::query()
            ->where('homeroom_user_id', $this->id)
            ->where('is_active', true)
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->get();

        return array_map(
            static fn (SchoolClass $class): string => $class->name(),
            KazakhSort::sort($classes, static fn (SchoolClass $c): array => [sprintf('%02d', $c->grade), $c->letter]),
        );
    }

    public static function normalizeLogin(string $login): string
    {
        return mb_strtolower(trim($login));
    }

    /**
     * `docs/api.md` файлындағы «Пайдаланушы нысаны».
     *
     * @return array<string, mixed>
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'role' => $this->role->value,
            'locale' => $this->locale->value,
            'must_change_password' => $this->must_change_password,
            'homeroom_classes' => $this->homeroomClassNames(),
        ];
    }
}
