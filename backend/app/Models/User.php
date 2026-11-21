<?php

namespace App\Models;

use App\Enums\Locale;
use App\Enums\Role;
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
            // Сыныптар кестесі 3-кезеңде пайда болады; оған дейін тізім бос.
            'homeroom_classes' => [],
        ];
    }
}
