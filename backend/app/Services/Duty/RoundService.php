<?php

namespace App\Services\Duty;

use App\Enums\RoundStatus;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\AttendanceRoundConflict;
use App\Models\DutyCode;
use App\Models\DutySession;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\EnrollmentService;
use App\Support\KazakhSort;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Кезекшінің аралауы: сыныптар күйі, тізім, сақтау. Ережелер: docs/кезең-4-жоспар.md. */
class RoundService
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    /**
     * Кодтың сменасындағы сыныптар және әрқайсысының бүгінгі аралау күйі.
     *
     * @return list<array<string, mixed>>
     */
    public function classes(DutyCode $code): array
    {
        $classes = SchoolClass::query()
            ->where('shift', $code->shift)
            ->where('is_active', true)
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->get();
        $ids = $classes->modelKeys();

        $counts = StudentEnrollment::query()->whereIn('class_id', $ids)->activeOn($code->date)
            ->selectRaw('class_id, count(*) as n')->groupBy('class_id')->pluck('n', 'class_id');
        $rounds = AttendanceRound::query()->whereIn('class_id', $ids)->whereDate('date', $code->date->toDateString())->get()->keyBy('class_id');
        $absent = AttendanceRecord::query()->whereIn('round_id', $rounds->modelKeys())->where('round_status', RoundStatus::Absent)
            ->selectRaw('round_id, count(*) as n')->groupBy('round_id')->pluck('n', 'round_id');

        $sorted = KazakhSort::sort($classes, static fn (SchoolClass $c): array => [sprintf('%02d', $c->grade), $c->letter]);

        return array_map(function (SchoolClass $class) use ($counts, $rounds, $absent): array {
            $round = $rounds->get($class->id);

            return [
                'id' => $class->id,
                'name' => $class->name(),
                'grade' => $class->grade,
                'student_count' => (int) ($counts[$class->id] ?? 0),
                'round' => $round === null ? null : $this->roundSummary($round, (int) ($absent[$round->id] ?? 0)),
            ];
        }, $sorted);
    }

    /**
     * Сменаның барлық сыныбының тізімі мен белгілері (планшеттің интернетсіз қоймасы үшін).
     * Сұраныс саны сынып санына тәуелсіз.
     *
     * @return list<array<string, mixed>>
     */
    public function rosters(DutyCode $code): array
    {
        $classes = SchoolClass::query()
            ->where('shift', $code->shift)->where('is_active', true)
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))
            ->get();
        $ids = $classes->modelKeys();

        $enrollments = StudentEnrollment::query()->whereIn('class_id', $ids)->activeOn($code->date)->with('student')->get()->groupBy('class_id');
        $rounds = AttendanceRound::query()->whereIn('class_id', $ids)->whereDate('date', $code->date->toDateString())->get()->keyBy('class_id');
        $records = AttendanceRecord::query()->whereIn('round_id', $rounds->modelKeys())->get()->groupBy('round_id');

        $sorted = KazakhSort::sort($classes, static fn (SchoolClass $c): array => [sprintf('%02d', $c->grade), $c->letter]);

        return array_map(function (SchoolClass $class) use ($enrollments, $rounds, $records): array {
            $round = $rounds->get($class->id);
            $byStudent = $round === null ? collect() : ($records->get($round->id) ?? collect())->keyBy('student_id');
            $students = KazakhSort::sort(
                ($enrollments->get($class->id) ?? collect())->map(static fn (StudentEnrollment $e): Student => $e->student),
                static fn (Student $s): array => [$s->last_name, $s->first_name, (string) $s->patronymic],
            );
            $rows = array_map(fn (Student $student): array => $this->studentRow($student, $byStudent->get($student->id)), $students);

            return [
                'class' => ['id' => $class->id, 'name' => $class->name()],
                'round' => $round === null ? null : $this->roundSummary($round, $this->absentCount($rows)),
                'students' => $rows,
            ];
        }, $sorted);
    }

    /**
     * Сыныптың бүгінгі тізімі және (болса) сақталған белгілері.
     *
     * @return array{round: array<string, mixed>|null, students: list<array<string, mixed>>}
     */
    public function roster(DutyCode $code, SchoolClass $class): array
    {
        $this->assertClassInShift($code, $class);
        $round = $this->findRound($class, $code);
        $records = $round === null ? collect() : $round->records()->get()->keyBy('student_id');

        $students = array_map(
            fn (Student $student): array => $this->studentRow($student, $records->get($student->id)),
            $this->enrollments->roster($class, CarbonImmutable::parse($code->date)),
        );

        return ['round' => $round === null ? null : $this->roundSummary($round, $this->absentCount($students)), 'students' => $students];
    }

    /** @return array<string, mixed> */
    private function studentRow(Student $student, ?AttendanceRecord $record): array
    {
        return [
            'id' => $student->id,
            'last_name' => $student->last_name,
            'first_name' => $student->first_name,
            'patronymic' => $student->patronymic,
            // Себеп, түсініктеме, кешігу сияқты өрістер кезекшіге берілмейді.
            'status' => ($record?->round_status ?? RoundStatus::Present)->value,
            'locked' => $record?->isLockedForDuty() ?? false,
        ];
    }

    /** @param  list<array<string, mixed>>  $students */
    private function absentCount(array $students): int
    {
        return count(array_filter($students, static fn (array $s): bool => $s['status'] === RoundStatus::Absent->value));
    }

    /**
     * Аралауды сақтайды не түзейді.
     *
     * @param  list<int>  $absentIds
     * @return array{stored_as: 'round'|'conflict', kept_locked: list<int>}
     *
     * @throws DomainException class_not_in_shift | unknown_students | round_changed
     */
    public function save(DutySession $session, SchoolClass $class, string $clientUuid, array $absentIds, ?int $revision, ?CarbonImmutable $clientTime): array
    {
        $code = $session->code;
        $this->assertClassInShift($code, $class);

        $roster = $this->enrollments->roster($class, CarbonImmutable::parse($code->date));
        $rosterIds = array_map(static fn (Student $s): int => $s->id, $roster);
        $absentIds = array_values(array_unique(array_map('intval', $absentIds)));
        if (array_diff($absentIds, $rosterIds) !== []) {
            throw new DomainException('unknown_students');
        }

        try {
            return DB::transaction(fn (): array => $this->saveLocked($session, $class, $clientUuid, $absentIds, $rosterIds, $revision, $clientTime));
        } catch (UniqueConstraintViolationException) {
            // Екі планшет бір сәтте бірінші рет сақтады: біреуі жазылып үлгерді. Екінші әрекет оны көріп, ережемен өңдейді.
            return DB::transaction(fn (): array => $this->saveLocked($session, $class, $clientUuid, $absentIds, $rosterIds, $revision, $clientTime));
        }
    }

    /**
     * @param  list<int>  $absentIds
     * @param  list<int>  $rosterIds
     * @return array{stored_as: 'round'|'conflict', kept_locked: list<int>}
     */
    private function saveLocked(DutySession $session, SchoolClass $class, string $clientUuid, array $absentIds, array $rosterIds, ?int $revision, ?CarbonImmutable $clientTime): array
    {
        $code = $session->code;
        $round = AttendanceRound::query()->where('class_id', $class->id)->whereDate('date', $code->date->toDateString())->lockForUpdate()->first();

        if ($round === null) {
            // Бұл планшеттің дерегі бұрын қақтығыс ретінде сақталған болса, қайта жіберілгенде де сол күйінде қалады.
            if (AttendanceRoundConflict::query()->where('client_uuid', $clientUuid)->exists()) {
                return ['stored_as' => 'conflict', 'kept_locked' => []];
            }
            $round = AttendanceRound::query()->create([
                'class_id' => $class->id, 'date' => $code->date, 'shift' => $code->shift,
                'duty_code_id' => $code->id, 'duty_session_id' => $session->id,
                'client_uuid' => $clientUuid, 'client_time' => $clientTime,
                'received_late' => $code->expires_at->isPast(),
            ]);
            $now = now();
            AttendanceRecord::query()->insert(array_map(static fn (int $id): array => [
                'round_id' => $round->id, 'student_id' => $id,
                'round_status' => in_array($id, $absentIds, true) ? RoundStatus::Absent->value : RoundStatus::Present->value,
                'round_status_source' => 'duty', 'created_at' => $now, 'updated_at' => $now,
            ], $rosterIds));

            return ['stored_as' => 'round', 'kept_locked' => []];
        }

        if ($round->client_uuid !== $clientUuid) {
            // Басқа планшеттің жеке аралауы: үстінен жазылмайды, завуч шешкенше бөлек сақталады.
            AttendanceRoundConflict::query()->firstOrCreate(['client_uuid' => $clientUuid], [
                'class_id' => $class->id, 'date' => $code->date, 'duty_code_id' => $code->id,
                'duty_session_id' => $session->id, 'client_time' => $clientTime, 'absent_student_ids' => $absentIds,
            ]);

            return ['stored_as' => 'conflict', 'kept_locked' => []];
        }

        $records = $round->records()->get()->keyBy('student_id');
        $changes = [];
        $keptLocked = [];
        $added = [];
        foreach ($rosterIds as $id) {
            $wanted = in_array($id, $absentIds, true) ? RoundStatus::Absent : RoundStatus::Present;
            /** @var AttendanceRecord|null $record */
            $record = $records->get($id);
            if ($record === null) {
                $added[$id] = $wanted; // аралаудан кейін сыныпқа қосылған оқушы
            } elseif ($record->round_status !== $wanted) {
                if ($record->isLockedForDuty()) {
                    $keptLocked[] = $id;
                } else {
                    $changes[] = [$record, $wanted];
                }
            }
        }

        if ($changes === [] && $added === []) {
            return ['stored_as' => 'round', 'kept_locked' => $keptLocked]; // қайта жіберілген сол дерек
        }
        if ($revision !== null && $revision !== $round->revision) {
            throw new DomainException('round_changed');
        }

        $now = now();
        foreach ($changes as [$record, $wanted]) {
            AttendanceAudit::query()->create([
                'record_id' => $record->id, 'field' => 'round_status',
                'old_value' => $record->round_status->value, 'new_value' => $wanted->value,
                'duty_session_id' => $session->id, 'changed_role' => 'duty', 'changed_at' => $now,
            ]);
            $record->update(['round_status' => $wanted]);
        }
        foreach ($added as $id => $wanted) {
            AttendanceRecord::query()->create(['round_id' => $round->id, 'student_id' => $id, 'round_status' => $wanted]);
        }
        $round->update(['revision' => $round->revision + 1]);

        return ['stored_as' => 'round', 'kept_locked' => $keptLocked];
    }

    public function findRound(SchoolClass $class, DutyCode $code): ?AttendanceRound
    {
        return AttendanceRound::query()->where('class_id', $class->id)->whereDate('date', $code->date->toDateString())->first();
    }

    /** @return array<string, mixed> */
    private function roundSummary(AttendanceRound $round, int $absentCount): array
    {
        return [
            'client_uuid' => $round->client_uuid,
            'revision' => $round->revision,
            'absent_count' => $absentCount,
            'saved_at' => $round->updated_at->toIso8601String(),
            'received_late' => $round->received_late,
        ];
    }

    private function assertClassInShift(DutyCode $code, SchoolClass $class): void
    {
        $ok = $class->is_active && $class->shift === $code->shift && $class->academicYear()->where('is_current', true)->exists();
        if (! $ok) {
            throw new DomainException('class_not_in_shift');
        }
    }
}
