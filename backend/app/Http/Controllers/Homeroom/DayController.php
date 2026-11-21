<?php

namespace App\Http\Controllers\Homeroom;

use App\Enums\Role;
use App\Enums\RoundStatus;
use App\Http\Controllers\Controller;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\DutySession;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\Attendance\RecordService;
use App\Services\CalendarService;
use App\Services\EnrollmentService;
use App\Support\KazakhSort;
use App\Support\StudentNames;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Сынып жетекшісі (өз сыныбы, соңғы күндер) және завуч (кез келген сынып, кез келген өткен күн):
 * күннің толық көрінісі, себеп, рұқсат, түсініктеме, түзету, тарих. Келісім: docs/api.md.
 */
class DayController extends Controller
{
    public function __construct(
        private readonly RecordService $records,
        private readonly EnrollmentService $enrollments,
        private readonly CalendarService $calendar,
    ) {}

    public function show(Request $request, SchoolClass $class, string $date): JsonResponse
    {
        $day = $this->day($date);
        if ($day === null || ! $this->mayView($request->user(), $class)) {
            return $this->error($day === null ? 'invalid_date' : 'forbidden', $day === null ? 422 : 403);
        }

        $round = AttendanceRound::query()->where('class_id', $class->id)->whereDate('date', $day->toDateString())->first();
        $records = $round === null ? collect() : $round->records()->get()->keyBy('student_id');

        return response()->json([
            'class' => ['id' => $class->id, 'name' => $class->name()],
            'date' => $day->toDateString(),
            'is_school_day' => $this->calendar->isSchoolDay($day),
            'editable' => $this->editError($request->user(), $class, $day) === null,
            'max_lessons' => Setting::int('max_lessons_per_shift'),
            // null: сол күні кезекші сыныпты араламаған.
            'duty' => $round === null || ! $round->duty_done ? null : [
                'saved_at' => $round->created_at->toIso8601String(),
                'received_late' => $round->received_late,
            ],
            'students' => array_map(
                fn (Student $s): array => $this->row($s, $records->get($s->id)),
                $this->enrollments->roster($class, $day),
            ),
        ]);
    }

    public function update(Request $request, SchoolClass $class, string $date, Student $student): JsonResponse
    {
        $day = $this->day($date);
        if ($day === null) {
            return $this->error('invalid_date');
        }
        /** @var User $user */
        $user = $request->user();
        if (($error = $this->editError($user, $class, $day)) !== null) {
            return $this->error($error, $error === 'forbidden' ? 403 : 422);
        }

        $data = $request->validate([
            'round_status' => ['sometimes', Rule::in([RoundStatus::Present->value, RoundStatus::Absent->value])],
            'arrived_lesson' => ['sometimes', 'nullable', 'integer'],
            'left_lesson' => ['sometimes', 'nullable', 'integer'],
            'absence_reason' => ['sometimes', 'nullable', Rule::in(RecordService::REASONS)],
            'leave_permission' => ['sometimes', 'nullable', Rule::in(RecordService::PERMISSIONS)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        try {
            $record = $this->records->change($class, $day, $student, $data, $user, $user->role === Role::Admin ? 'admin' : 'homeroom');
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['student' => $this->row($student, $record)]);
    }

    public function history(Request $request, SchoolClass $class, string $date, Student $student): JsonResponse
    {
        $day = $this->day($date);
        if ($day === null || ! $this->mayView($request->user(), $class)) {
            return $this->error($day === null ? 'invalid_date' : 'forbidden', $day === null ? 422 : 403);
        }

        $round = AttendanceRound::query()->where('class_id', $class->id)->whereDate('date', $day->toDateString())->first();
        $record = $round === null ? null : AttendanceRecord::query()->where('round_id', $round->id)->where('student_id', $student->id)->first();
        if ($record === null) {
            return response()->json(['created' => null, 'changes' => []]);
        }

        $audit = AttendanceAudit::query()->where('record_id', $record->id)->orderBy('id')->get();
        $users = User::query()->whereIn('id', $audit->pluck('changed_by')->filter())->pluck('full_name', 'id');
        $devices = DutySession::query()->whereIn('id', $audit->pluck('duty_session_id')->push($round->duty_session_id)->filter())->pluck('device_number', 'id');

        return response()->json([
            // Жазбаның пайда болуы: кезекші аралағанда не мұғалім, жетекші алғаш тіркегенде.
            'created' => ['at' => $record->created_at->toIso8601String(), 'by_duty' => $audit->where('field', 'round_status')->whereNull('old_value')->isEmpty()],
            'changes' => $audit->map(static fn (AttendanceAudit $a): array => [
                'field' => $a->field,
                'old' => $a->old_value,
                'new' => $a->new_value,
                'role' => $a->changed_role,
                'by' => $a->changed_by === null ? null : ($users[$a->changed_by] ?? null),
                'device_number' => $a->duty_session_id === null ? null : ($devices[$a->duty_session_id] ?? null),
                'at' => $a->changed_at->toIso8601String(),
            ])->all(),
        ]);
    }

    /** Анықталмағандар: себебі қойылмаған жоқ оқушылар мен рұқсаты қойылмаған кетулер (түзетуге болатын мерзімде). */
    public function pending(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $today = CarbonImmutable::today();
        $from = $today->subDays(Setting::int('homeroom_edit_days'));

        $classes = $user->role === Role::Admin
            ? SchoolClass::query()->where('is_active', true)->whereHas('academicYear', fn ($q) => $q->where('is_current', true))->get()
            : collect($user->homeroomClasses());
        $classes = $classes->keyBy('id');

        $rounds = AttendanceRound::query()->whereIn('class_id', $classes->keys())
            ->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $today->toDateString())->get()->keyBy('id');
        $records = AttendanceRecord::query()->whereIn('round_id', $rounds->keys())
            ->where(fn ($q) => $q
                ->where(fn ($a) => $a->where('round_status', RoundStatus::Absent)->whereNull('arrived_lesson')->whereNull('left_lesson')->whereNull('absence_reason'))
                ->orWhere(fn ($l) => $l->whereNotNull('left_lesson')->whereNull('leave_permission')))
            ->get();
        $students = Student::query()->whereIn('id', $records->pluck('student_id'))->get()->keyBy('id');
        $names = StudentNames::labels($students, $records->mapWithKeys(static fn (AttendanceRecord $r): array => [$r->student_id => $rounds[$r->round_id]->class_id])->all(), $today);

        $items = $records->map(function (AttendanceRecord $record) use ($rounds, $classes, $students, $names): array {
            $round = $rounds[$record->round_id];
            $student = $students[$record->student_id];

            return [
                'class' => ['id' => $round->class_id, 'name' => $classes[$round->class_id]->name()],
                'date' => $round->date->toDateString(),
                'student' => ['id' => $student->id, 'name' => $names[$student->id]],
                'kind' => $record->pendingKind(),
            ];
        })->all();

        // Жаңа күндер алда; күн ішінде сынып, содан кейін оқушы қазақ әліпбиі ретімен.
        $items = KazakhSort::sort($items, static fn (array $i): array => [
            sprintf('%08d', 99999999 - (int) str_replace('-', '', $i['date'])),
            sprintf('%02d', (int) $i['class']['name']), $i['class']['name'], $i['student']['name'],
        ]);

        return response()->json(['items' => $items]);
    }

    private function day(string $date): ?CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false
            ? CarbonImmutable::parse($date)->startOfDay()
            : null;
    }

    private function mayView(User $user, SchoolClass $class): bool
    {
        return $user->role === Role::Admin || $class->homeroom_user_id === $user->id;
    }

    /** Өзгертуге болмайтын себеп (болса): forbidden | date_not_editable. */
    private function editError(User $user, SchoolClass $class, CarbonImmutable $day): ?string
    {
        if (! $this->mayView($user, $class)) {
            return 'forbidden';
        }
        $today = CarbonImmutable::today();
        if ($day->gt($today)) {
            return 'date_not_editable';
        }
        // Жетекші соңғы күндерді ғана өзгертеді; одан ескісін тек завуч.
        if ($user->role !== Role::Admin && $day->lt($today->subDays(Setting::int('homeroom_edit_days')))) {
            return 'date_not_editable';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function row(Student $student, ?AttendanceRecord $record): array
    {
        return [
            'id' => $student->id,
            'last_name' => $student->last_name,
            'first_name' => $student->first_name,
            'patronymic' => $student->patronymic,
            // null: сол күні бұл оқушыға ешқандай белгі жоқ.
            'round_status' => $record?->round_status->value,
            'arrived_lesson' => $record?->arrived_lesson,
            'left_lesson' => $record?->left_lesson,
            'absence_reason' => $record?->absence_reason,
            'leave_permission' => $record?->leave_permission,
            'note' => $record?->note,
            'pending' => $record?->pendingKind(),
        ];
    }
}
