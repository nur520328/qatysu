<?php

namespace App\Services\Attendance;

use App\Enums\RoundStatus;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Мұғалім, жетекші және завучтың қатысу жазбасын өзгертуі. Барлық өзгеріс осы жерден өтеді:
 * өрістердің сәйкестігі тексеріледі, әр өрістің өзгерісі аудитке жазылады.
 * Ережелер: docs/жобалық-құжат.md 4-бөлім, docs/кезең-6-жоспар.md.
 */
class RecordService
{
    public const REASONS = ['sick', 'excused', 'unexcused'];

    public const PERMISSIONS = ['permitted', 'unpermitted'];

    private const FIELDS = ['round_status', 'arrived_lesson', 'left_lesson', 'absence_reason', 'leave_permission', 'note'];

    public function __construct(private readonly CalendarService $calendar) {}

    /**
     * Жазбаны өзгертеді (жоқ болса алдымен ашады). `$changes` ішінде тек өзгертілетін өрістер болады.
     *
     * @param  array<string, mixed>  $changes
     * @param  'teacher'|'homeroom'|'admin'  $role
     *
     * @throws DomainException not_school_day | student_not_in_class | lesson_out_of_range | leave_before_arrival
     *                         | reason_needs_full_absence | permission_needs_leave
     */
    public function change(SchoolClass $class, CarbonImmutable $date, Student $student, array $changes, User $actor, string $role): AttendanceRecord
    {
        $date = $date->startOfDay();
        if (! $this->calendar->isSchoolDay($date)) {
            throw new DomainException('not_school_day');
        }
        $enrolled = StudentEnrollment::query()->where('student_id', $student->id)->where('class_id', $class->id)->activeOn($date)->exists();
        if (! $enrolled) {
            throw new DomainException('student_not_in_class');
        }

        return DB::transaction(function () use ($class, $date, $student, $changes, $actor, $role): AttendanceRecord {
            $round = AttendanceRound::query()->where('class_id', $class->id)->whereDate('date', $date->toDateString())->lockForUpdate()->first()
                // Кезекші әлі араламаған: күннің жазбасы «аралау жасалмаған» белгісімен ашылады.
                ?? AttendanceRound::query()->create([
                    'class_id' => $class->id, 'date' => $date, 'shift' => $class->shift,
                    'client_uuid' => (string) Str::uuid(), 'duty_done' => false,
                ]);

            $record = AttendanceRecord::query()->where('round_id', $round->id)->where('student_id', $student->id)->lockForUpdate()->first();
            $created = $record === null;
            if ($created) {
                // Кезекші белгілемеген оқушы. Әдепкі белгі тіркеудің мағынасынан шығады: кешікті дегені
                // бастапқыда болмаған, кетіп қалды дегені болған.
                $default = $changes['round_status'] ?? (array_key_exists('left_lesson', $changes) && ! array_key_exists('arrived_lesson', $changes) ? 'present' : 'absent');
                $record = new AttendanceRecord(['round_id' => $round->id, 'student_id' => $student->id, 'round_status' => $default, 'round_status_source' => 'staff']);
                unset($changes['round_status']);
            }

            $before = $this->values($record);
            $after = $this->normalize(array_merge($before, array_intersect_key($changes, array_flip(self::FIELDS))), $before);
            $this->validate($after);

            $record->fill([
                'round_status' => $after['round_status'],
                'arrived_lesson' => $after['arrived_lesson'],
                'left_lesson' => $after['left_lesson'],
                'absence_reason' => $after['absence_reason'],
                'leave_permission' => $after['leave_permission'],
                'note' => $after['note'],
            ]);
            if ($after['round_status'] !== $before['round_status']) {
                $record->round_status_source = 'staff';
            }
            if ($after['arrived_lesson'] !== $before['arrived_lesson']) {
                $record->arrived_by = $after['arrived_lesson'] === null ? null : $actor->id;
            }
            if ($after['left_lesson'] !== $before['left_lesson']) {
                $record->left_by = $after['left_lesson'] === null ? null : $actor->id;
            }

            // Жаңа ашылған жазбада бастапқы «бар/жоқ» белгісі де тарихқа түседі: кім ашқаны көрінуі тиіс.
            $changed = array_filter(self::FIELDS, static fn (string $f): bool => $after[$f] !== $before[$f] || ($created && $f === 'round_status'));
            if ($changed === [] && ! $created) {
                return $record;
            }
            $record->updated_by = $actor->id;
            $record->save();

            $now = now();
            foreach ($changed as $field) {
                AttendanceAudit::query()->create([
                    'record_id' => $record->id, 'field' => $field,
                    'old_value' => $created ? null : $this->text($before[$field]),
                    'new_value' => $this->text($after[$field]),
                    'changed_by' => $actor->id, 'changed_role' => $role, 'changed_at' => $now,
                ]);
            }
            // Кезекші ескі күйден бастап түзетсе, «тізім өзгерді» деген жауап алады.
            $round->update(['revision' => $round->revision + 1]);

            return $record;
        });
    }

    /** @return array<string, mixed> */
    private function values(AttendanceRecord $record): array
    {
        return [
            'round_status' => $record->round_status->value,
            'arrived_lesson' => $record->arrived_lesson,
            'left_lesson' => $record->left_lesson,
            'absence_reason' => $record->absence_reason,
            'leave_permission' => $record->leave_permission,
            'note' => $record->note,
        ];
    }

    /**
     * Негізгі өріс өзгергенде оған тәуелді өріс мағынасын жоғалтса, тазаланады (ол да аудитке түседі):
     * оқушы келген не кеткен болса, «күні бойы болмады» себебі; кету алынса, рұқсат белгісі.
     * Тәуелді өрісті сұраныстың өзі қойған болса, тазаланбайды: сәйкессіздік қате ретінде қайтады.
     *
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private function normalize(array $after, array $before): array
    {
        $after['arrived_lesson'] = $after['arrived_lesson'] === null ? null : (int) $after['arrived_lesson'];
        $after['left_lesson'] = $after['left_lesson'] === null ? null : (int) $after['left_lesson'];
        $after['note'] = $after['note'] === null || trim((string) $after['note']) === '' ? null : trim((string) $after['note']);

        $fullAbsence = $after['round_status'] === RoundStatus::Absent->value && $after['arrived_lesson'] === null && $after['left_lesson'] === null;
        if (! $fullAbsence && $after['absence_reason'] === $before['absence_reason']) {
            $after['absence_reason'] = null;
        }
        if ($after['left_lesson'] === null && $after['leave_permission'] === $before['leave_permission']) {
            $after['leave_permission'] = null;
        }

        return $after;
    }

    /** @param  array<string, mixed>  $v */
    private function validate(array $v): void
    {
        $max = Setting::int('max_lessons_per_shift');
        if (($v['arrived_lesson'] !== null && ($v['arrived_lesson'] < 1 || $v['arrived_lesson'] > $max))
            || ($v['left_lesson'] !== null && ($v['left_lesson'] < 2 || $v['left_lesson'] > $max))) {
            throw new DomainException('lesson_out_of_range');
        }
        if ($v['arrived_lesson'] !== null && $v['left_lesson'] !== null && $v['left_lesson'] < $v['arrived_lesson']) {
            throw new DomainException('leave_before_arrival');
        }
        $fullAbsence = $v['round_status'] === RoundStatus::Absent->value && $v['arrived_lesson'] === null && $v['left_lesson'] === null;
        if ($v['absence_reason'] !== null && ! $fullAbsence) {
            throw new DomainException('reason_needs_full_absence');
        }
        if ($v['leave_permission'] !== null && $v['left_lesson'] === null) {
            throw new DomainException('permission_needs_leave');
        }
    }

    private function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
