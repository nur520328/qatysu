<?php

namespace App\Models;

use App\Enums\RoundStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Бір оқушының бір күнгі қатысу жазбасы. Өрістер мағынасы: docs/жобалық-құжат.md, 4-бөлім.
 *
 * @property int $id
 * @property int $round_id
 * @property int $student_id
 * @property RoundStatus $round_status
 * @property string $round_status_source duty | staff
 * @property int|null $arrived_lesson
 * @property int|null $left_lesson
 * @property int|null $arrived_by
 * @property int|null $left_by
 * @property string|null $absence_reason
 * @property string|null $leave_permission
 * @property string|null $note
 */
#[Fillable(['round_id', 'student_id', 'round_status', 'round_status_source', 'arrived_lesson', 'arrived_by', 'left_lesson', 'left_by', 'absence_reason', 'leave_permission', 'note', 'updated_by'])]
class AttendanceRecord extends Model
{
    protected $attributes = ['round_status_source' => 'duty'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['round_status' => RoundStatus::class, 'arrived_lesson' => 'integer', 'left_lesson' => 'integer'];
    }

    /**
     * Мұғалім, жетекші не завуч тиген жазбаның «бар/жоқ» белгісін кезекші өзгерте алмайды:
     * олардың мәні кезекшінікінен әрқашан жоғары (жобалық құжат, 6-бөлім).
     */
    public function isLockedForDuty(): bool
    {
        return $this->round_status_source === 'staff' || $this->arrived_lesson !== null || $this->left_lesson !== null || $this->absence_reason !== null;
    }

    /** Күні бойы жоқ: «жоқ» деп белгіленген, келуі де, кетуі де тіркелмеген. */
    public function isFullAbsence(): bool
    {
        return $this->round_status === RoundStatus::Absent && $this->arrived_lesson === null && $this->left_lesson === null;
    }

    /** Жетекшінің қандай шешімі күтіліп тұр: permission (кетуге рұқсат), reason (жоқ болу себебі) не null. */
    public function pendingKind(): ?string
    {
        return match (true) {
            $this->left_lesson !== null && $this->leave_permission === null => 'permission',
            $this->isFullAbsence() && $this->absence_reason === null => 'reason',
            default => null,
        };
    }
}
