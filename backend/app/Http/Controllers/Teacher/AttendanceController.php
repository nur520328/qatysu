<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Services\Attendance\RecordService;
use App\Services\CalendarService;
use App\Services\EnrollmentService;
use App\Support\KazakhSort;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Пән мұғалімі: бүгінгі күн, кез келген сынып; тек «N-сабақтан келді» мен «N-сабақтан кетіп қалды».
 * Себеп, рұқсат, түсініктеме мұнда берілмейді. Келісім: docs/api.md.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly RecordService $records,
        private readonly EnrollmentService $enrollments,
        private readonly CalendarService $calendar,
    ) {}

    public function classes(): JsonResponse
    {
        $today = CarbonImmutable::today();
        $classes = SchoolClass::query()->where('is_active', true)
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))->get();
        $sorted = KazakhSort::sort($classes, static fn (SchoolClass $c): array => [sprintf('%02d', $c->grade), $c->letter]);

        return response()->json([
            'date' => $today->toDateString(),
            'is_school_day' => $this->calendar->isSchoolDay($today),
            'max_lessons' => Setting::int('max_lessons_per_shift'),
            'classes' => array_map(static fn (SchoolClass $c): array => ['id' => $c->id, 'name' => $c->name(), 'grade' => $c->grade, 'shift' => $c->shift->value], $sorted),
        ]);
    }

    public function show(Request $request, SchoolClass $class): JsonResponse
    {
        if (! $this->open($class)) {
            return $this->error('class_not_found', 404);
        }
        $today = CarbonImmutable::today();
        $students = $this->enrollments->roster($class, $today);
        $records = $this->recordsFor([$class->id], $today);

        return response()->json([
            'class' => ['id' => $class->id, 'name' => $class->name()],
            'students' => array_map(fn (Student $s): array => $this->row($s, $class, $records->get($class->id.'|'.$s->id), $request->user()), $students),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:3', 'max:60']]);
        $today = CarbonImmutable::today();
        $prefix = addcslashes((string) Student::cleanName($data['q']), '%_\\').'%';

        $enrollments = StudentEnrollment::query()->activeOn($today)
            ->whereHas('student', fn ($q) => $q->where('last_name', 'like', $prefix))
            ->whereHas('schoolClass', fn ($q) => $q->where('is_active', true)->whereHas('academicYear', fn ($y) => $y->where('is_current', true)))
            ->with(['student', 'schoolClass'])->limit(30)->get();
        $records = $this->recordsFor($enrollments->pluck('class_id')->unique()->all(), $today);

        $sorted = KazakhSort::sort($enrollments, static fn (StudentEnrollment $e): array => [$e->student->last_name, $e->student->first_name, (string) $e->student->patronymic]);

        return response()->json(['students' => array_map(
            fn (StudentEnrollment $e): array => $this->row($e->student, $e->schoolClass, $records->get($e->class_id.'|'.$e->student_id), $request->user()),
            $sorted,
        )]);
    }

    public function arrive(Request $request, Student $student): JsonResponse
    {
        return $this->register($request, $student, 'arrived_lesson', 'arrived_by');
    }

    public function leave(Request $request, Student $student): JsonResponse
    {
        return $this->register($request, $student, 'left_lesson', 'left_by');
    }

    public function undoArrive(Request $request, Student $student): JsonResponse
    {
        return $this->undo($request, $student, 'arrived_lesson', 'arrived_by');
    }

    public function undoLeave(Request $request, Student $student): JsonResponse
    {
        return $this->undo($request, $student, 'left_lesson', 'left_by');
    }

    private function register(Request $request, Student $student, string $field, string $byField): JsonResponse
    {
        $data = $request->validate(['lesson' => ['required', 'integer', 'min:1', 'max:12']]);
        /** @var User $user */
        $user = $request->user();
        $class = $this->classOf($student);
        if ($class === null) {
            return $this->error('student_not_in_class', 404);
        }

        $record = $this->recordOf($class, $student);
        // Басқа біреу тіркегенін мұғалім өзгерте алмайды: оны жетекші не завуч түзейді.
        if ($record !== null && $record->{$field} !== null && $record->{$byField} !== $user->id) {
            return $this->error('already_registered', 409);
        }
        // Жетекші «күні бойы болмады» деп себеп қойған оқушыны мұғалім «келді» деп өзгертпейді.
        if ($record !== null && $record->absence_reason !== null) {
            return $this->error('reason_already_set', 409);
        }

        try {
            $record = $this->records->change($class, CarbonImmutable::today(), $student, [$field => $data['lesson']], $user, 'teacher');
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['student' => $this->row($student, $class, $record, $user)]);
    }

    private function undo(Request $request, Student $student, string $field, string $byField): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $class = $this->classOf($student);
        $record = $class === null ? null : $this->recordOf($class, $student);
        if ($class === null || $record === null || $record->{$field} === null) {
            return $this->error('not_registered', 404);
        }
        // Тек өзі тіркегенін, және жетекші рұқсат белгісін қойғанға дейін.
        if ($record->{$byField} !== $user->id || ($field === 'left_lesson' && $record->leave_permission !== null)) {
            return $this->error('cannot_undo', 409);
        }

        $record = $this->records->change($class, CarbonImmutable::today(), $student, [$field => null], $user, 'teacher');

        return response()->json(['student' => $this->row($student, $class, $record, $user)]);
    }

    private function open(SchoolClass $class): bool
    {
        return $class->is_active && $class->academicYear()->where('is_current', true)->exists();
    }

    private function classOf(Student $student): ?SchoolClass
    {
        $class = $this->enrollments->current($student)?->schoolClass;

        return $class !== null && $this->open($class) ? $class : null;
    }

    private function recordOf(SchoolClass $class, Student $student): ?AttendanceRecord
    {
        return $this->recordsFor([$class->id], CarbonImmutable::today())->get($class->id.'|'.$student->id);
    }

    /**
     * @param  list<int>  $classIds
     * @return Collection<string, AttendanceRecord> кілті «сынып|оқушы»
     */
    private function recordsFor(array $classIds, CarbonImmutable $date): Collection
    {
        $rounds = AttendanceRound::query()->whereIn('class_id', $classIds)->whereDate('date', $date->toDateString())->pluck('class_id', 'id');

        return AttendanceRecord::query()->whereIn('round_id', $rounds->keys())->get()
            ->keyBy(static fn (AttendanceRecord $r): string => $rounds[$r->round_id].'|'.$r->student_id);
    }

    /** @return array<string, mixed> */
    private function row(Student $student, SchoolClass $class, ?AttendanceRecord $record, User $user): array
    {
        return [
            'id' => $student->id,
            'last_name' => $student->last_name,
            'first_name' => $student->first_name,
            'patronymic' => $student->patronymic,
            'class' => ['id' => $class->id, 'name' => $class->name()],
            // null: бүгін бұл оқушы әлі белгіленген жоқ.
            'round_status' => $record?->round_status->value,
            'arrived_lesson' => $record?->arrived_lesson,
            'left_lesson' => $record?->left_lesson,
            'can_undo_arrival' => $record !== null && $record->arrived_lesson !== null && $record->arrived_by === $user->id,
            'can_undo_leave' => $record !== null && $record->left_lesson !== null && $record->left_by === $user->id && $record->leave_permission === null,
        ];
    }
}
