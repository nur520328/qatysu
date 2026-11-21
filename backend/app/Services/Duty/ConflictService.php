<?php

namespace App\Services\Duty;

use App\Enums\RoundStatus;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\AttendanceRoundConflict;
use App\Models\DutySession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\KazakhSort;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Екі планшеттің бір сыныпты бөлек белгілеуі (интернетсіз режимде болады). Завуч екі нұсқаны
 * қатар көріп, біреуін таңдайды. Ереже: docs/кезең-5-жоспар.md, 8-шешім.
 */
class ConflictService
{
    /**
     * Шешілмеген қақтығыстар, жаңасынан ескісіне қарай.
     *
     * @return list<array<string, mixed>>
     */
    public function open(): array
    {
        $conflicts = AttendanceRoundConflict::query()->whereNull('resolved_at')->latest('id')->get();
        if ($conflicts->isEmpty()) {
            return [];
        }

        $classes = SchoolClass::query()->whereIn('id', $conflicts->pluck('class_id'))->get()->keyBy('id');
        $rounds = AttendanceRound::query()->whereIn('class_id', $conflicts->pluck('class_id'))->get()
            ->keyBy(static fn (AttendanceRound $r): string => $r->class_id.'|'.$r->date->toDateString());
        $records = AttendanceRecord::query()->whereIn('round_id', $rounds->pluck('id'))->get()->groupBy('round_id');
        $devices = DutySession::query()
            ->whereIn('id', $conflicts->pluck('duty_session_id')->merge($rounds->pluck('duty_session_id'))->filter())
            ->pluck('device_number', 'id');
        $studentIds = $records->flatten()->pluck('student_id')->merge($conflicts->pluck('absent_student_ids')->flatten())->unique();
        $students = Student::query()->whereIn('id', $studentIds)->get()->keyBy('id');

        $names = static function (iterable $ids) use ($students): array {
            $list = array_values(array_filter(array_map(static fn ($id) => $students->get((int) $id), [...$ids])));
            $sorted = KazakhSort::sort($list, static fn (Student $s): array => [$s->last_name, $s->first_name, (string) $s->patronymic]);

            return array_map(static fn (Student $s): array => ['id' => $s->id, 'name' => trim($s->last_name.' '.$s->first_name)], $sorted);
        };

        return $conflicts->map(function (AttendanceRoundConflict $conflict) use ($classes, $rounds, $records, $devices, $names): array {
            /** @var AttendanceRound $round */
            $round = $rounds->get($conflict->class_id.'|'.$conflict->date->toDateString());
            $roundRecords = $records->get($round->id) ?? collect();
            $inRound = $roundRecords->pluck('student_id')->all();

            return [
                'id' => $conflict->id,
                'class' => ['id' => $conflict->class_id, 'name' => $classes->get($conflict->class_id)?->name() ?? ''],
                'date' => $conflict->date->toDateString(),
                'student_count' => count($inRound),
                'current' => [
                    'device_number' => $devices[$round->duty_session_id] ?? null,
                    'saved_at' => $round->updated_at->toIso8601String(),
                    'absent' => $names($roundRecords->where('round_status', RoundStatus::Absent)->pluck('student_id')),
                ],
                'incoming' => [
                    'device_number' => $devices[$conflict->duty_session_id] ?? null,
                    'marked_at' => $conflict->client_time?->toIso8601String(),
                    'received_at' => $conflict->created_at->toIso8601String(),
                    // Аралаудан кейін сыныптан шыққан оқушы көрсетілмейді: оның жазбасы бәрібір өзгермейді.
                    'absent' => $names(array_intersect($conflict->absent_student_ids, $inRound)),
                ],
            ];
        })->all();
    }

    /**
     * @param  'keep'|'replace'  $resolution  keep: сервердегі нұсқа қалады; replace: кейін жеткен нұсқа қолданылады
     *
     * @throws DomainException conflict_already_resolved
     */
    public function resolve(AttendanceRoundConflict $conflict, string $resolution, User $admin): void
    {
        DB::transaction(function () use ($conflict, $resolution, $admin): void {
            $conflict = AttendanceRoundConflict::query()->lockForUpdate()->findOrFail($conflict->id);
            if ($conflict->resolved_at !== null) {
                throw new DomainException('conflict_already_resolved');
            }

            if ($resolution === 'replace') {
                $round = AttendanceRound::query()->where('class_id', $conflict->class_id)
                    ->whereDate('date', $conflict->date->toDateString())->lockForUpdate()->firstOrFail();
                $now = now();
                $changed = false;
                foreach ($round->records()->get() as $record) {
                    $wanted = in_array($record->student_id, $conflict->absent_student_ids, true) ? RoundStatus::Absent : RoundStatus::Present;
                    if ($record->round_status === $wanted) {
                        continue;
                    }
                    AttendanceAudit::query()->create([
                        'record_id' => $record->id, 'field' => 'round_status',
                        'old_value' => $record->round_status->value, 'new_value' => $wanted->value,
                        'changed_by' => $admin->id, 'changed_role' => 'admin', 'changed_at' => $now,
                    ]);
                    // Завуч таңдаған мәнді кезекші кейін өзгерте алмайды.
                    $record->update(['round_status' => $wanted, 'round_status_source' => 'staff', 'updated_by' => $admin->id]);
                    $changed = true;
                }
                if ($changed) {
                    $round->update(['revision' => $round->revision + 1]);
                }
            }

            $conflict->update(['resolved_at' => now(), 'resolved_by' => $admin->id, 'resolution' => $resolution]);
        });
    }
}
