<?php

namespace App\Models;

use App\Enums\ClassLanguage;
use App\Enums\Shift;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Сынып. PHP-де `class` сөзі бос емес, сондықтан модель `SchoolClass` деп аталады;
 * кестенің аты жобалық құжаттағыдай `classes`.
 *
 * @property int $id
 * @property int $academic_year_id
 * @property int $grade
 * @property string $letter
 * @property Shift $shift
 * @property ClassLanguage $language
 * @property int|null $homeroom_user_id
 * @property bool $is_active
 * @property-read User|null $homeroom
 */
#[Table('classes')]
#[Fillable(['academic_year_id', 'grade', 'letter', 'shift', 'language', 'homeroom_user_id', 'is_active'])]
class SchoolClass extends Model
{
    /** Дерекқордағы әдепкі мәндермен бірдей: жаңа жасалған нысан да оларды бірден көрсетеді. */
    protected $attributes = ['is_active' => true, 'language' => 'kk'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'shift' => Shift::class,
            'language' => ClassLanguage::class,
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function homeroom(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeroom_user_id');
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return HasMany<StudentEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(StudentEnrollment::class, 'class_id');
    }

    public function name(): string
    {
        return $this->grade.$this->letter;
    }

    /** @return array<string, mixed> */
    public function toApi(?int $studentCount = null): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name(),
            'grade' => $this->grade,
            'letter' => $this->letter,
            'shift' => $this->shift->value,
            'language' => $this->language->value,
            'is_active' => $this->is_active,
            'homeroom' => $this->homeroom === null ? null : [
                'id' => $this->homeroom->id,
                'full_name' => $this->homeroom->full_name,
            ],
            'student_count' => $studentCount,
        ];
    }
}
