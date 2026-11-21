<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\EnrollmentService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** docs/api.md, «Оқушылар». */
class StudentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function index(Request $request, SchoolClass $class): JsonResponse
    {
        $date = $this->dateOrToday($request->query('date'));
        if ($date === null) {
            return $this->error('invalid_date');
        }

        return response()->json([
            'class' => $class->load('homeroom')->toApi(),
            'date' => $date->toDateString(),
            'students' => array_map(
                static fn (Student $s): array => $s->toApi($class->id),
                $this->enrollments->roster($class, $date),
            ),
        ]);
    }

    public function store(Request $request, SchoolClass $class): JsonResponse
    {
        $names = $this->validatedNames($request, required: true);
        if ($names instanceof JsonResponse) {
            return $names;
        }
        if ($this->duplicateInClass($class, $names) !== null) {
            return $this->error('student_exists', 409);
        }

        try {
            $student = DB::transaction(function () use ($names, $class): Student {
                $student = Student::query()->create($names);
                $this->enrollments->enroll($student, $class, CarbonImmutable::today());

                return $student;
            });
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['student' => $student->toApi($class->id)], 201);
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $names = $this->validatedNames($request, required: false);
        if ($names instanceof JsonResponse) {
            return $names;
        }
        $student->fill($names)->save();

        return response()->json(['student' => $student->toApi($this->enrollments->current($student)?->class_id)]);
    }

    public function transfer(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate([
            'class_id' => ['required', 'integer'],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $class = SchoolClass::query()->find($data['class_id']);
        if ($class === null) {
            return $this->error('class_not_found', 404);
        }
        $date = $this->pastOrToday($data['date'] ?? null);
        if ($date === null) {
            return $this->error('invalid_date');
        }

        try {
            $this->enrollments->transfer($student, $class, $date);
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['student' => $student->toApi($class->id)]);
    }

    public function withdraw(Request $request, Student $student): JsonResponse
    {
        $data = $request->validate(['date' => ['sometimes', 'nullable', 'date_format:Y-m-d']]);
        $date = $this->pastOrToday($data['date'] ?? null);
        if ($date === null) {
            return $this->error('invalid_date');
        }

        try {
            $this->enrollments->withdraw($student, $date);
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }

        return response()->json(['student' => $student->refresh()->toApi()]);
    }

    /**
     * @return array<string, string|null>|JsonResponse
     */
    private function validatedNames(Request $request, bool $required): array|JsonResponse
    {
        $rule = $required ? 'required' : 'sometimes';
        $data = $request->validate([
            'last_name' => [$rule, 'string', 'max:60'],
            'first_name' => [$rule, 'string', 'max:60'],
            'patronymic' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        $clean = [];
        foreach ($data as $field => $value) {
            $clean[$field] = Student::cleanName($value);
            if ($clean[$field] === null && $field !== 'patronymic') {
                return response()->json(['code' => 'validation_failed', 'fields' => [$field]], 422);
            }
        }

        return $clean;
    }

    /** @param  array<string, string|null>  $names */
    private function duplicateInClass(SchoolClass $class, array $names): ?Student
    {
        foreach ($this->enrollments->roster($class, CarbonImmutable::today()) as $existing) {
            if (mb_strtolower($existing->last_name) === mb_strtolower((string) $names['last_name'])
                && mb_strtolower($existing->first_name) === mb_strtolower((string) $names['first_name'])
                && mb_strtolower((string) $existing->patronymic) === mb_strtolower((string) ($names['patronymic'] ?? ''))) {
                return $existing;
            }
        }

        return null;
    }

    private function dateOrToday(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return CarbonImmutable::today();
        }
        if (! is_string($value) || preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) !== 1) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) ? CarbonImmutable::create($y, $m, $d) : null;
    }

    /** Көшіру мен шығару болашақ күнге жазылмайды. */
    private function pastOrToday(?string $value): ?CarbonImmutable
    {
        $date = $this->dateOrToday($value);

        return $date === null || $date->gt(CarbonImmutable::today()) ? null : $date;
    }
}
