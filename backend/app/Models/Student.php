<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Оқушы. Әдейі тек аты-жөні сақталады: ЖСН, туған күн, телефон платформада жоқ.
 *
 * @property int $id
 * @property string $last_name
 * @property string $first_name
 * @property string|null $patronymic
 * @property bool $is_active
 */
#[Fillable(['last_name', 'first_name', 'patronymic', 'is_active'])]
class Student extends Model
{
    /** Дерекқордағы әдепкі мәнмен бірдей. */
    protected $attributes = ['is_active' => true];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return HasMany<StudentEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class);
    }

    /**
     * Аты-жөн бөлігін сақтауға дайындайды: шеткі және қатар бос орындарды алады.
     * Excel-ден келген мәндерде олар жиі кездеседі және қайталанған оқушыны тануға кедергі жасайды.
     */
    public static function cleanName(?string $value): ?string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return $clean === '' ? null : $clean;
    }

    /** @return array<string, mixed> */
    public function toApi(?int $classId = null): array
    {
        return [
            'id' => $this->id,
            'last_name' => $this->last_name,
            'first_name' => $this->first_name,
            'patronymic' => $this->patronymic,
            'class_id' => $classId,
        ];
    }
}
