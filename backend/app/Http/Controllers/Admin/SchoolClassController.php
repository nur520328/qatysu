<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ClassLanguage;
use App\Enums\Shift;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\ClassName;
use App\Support\KazakhSort;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** docs/api.md, «Сыныптар». */
class SchoolClassController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = $request->filled('year_id')
            ? AcademicYear::query()->find($request->integer('year_id'))
            : AcademicYear::current();
        if ($year === null) {
            return response()->json(['year' => null, 'classes' => []]);
        }

        $classes = SchoolClass::query()->with('homeroom')->where('academic_year_id', $year->id)->get();
        $counts = StudentEnrollment::query()
            ->whereIn('class_id', $classes->modelKeys())
            ->activeOn(today())
            ->selectRaw('class_id, count(*) as total')
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        $sorted = KazakhSort::sort($classes, static fn (SchoolClass $c): array => [sprintf('%02d', $c->grade), $c->letter]);

        return response()->json([
            'year' => $year->toApi(),
            'classes' => array_map(
                static fn (SchoolClass $c): array => $c->toApi((int) ($counts[$c->id] ?? 0)),
                $sorted,
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:4'],
            'shift' => ['required', Rule::in(Shift::values())],
            'language' => ['sometimes', Rule::in(ClassLanguage::values())],
            'homeroom_user_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $year = AcademicYear::current();
        if ($year === null) {
            return $this->error('no_current_year', 409);
        }
        $name = ClassName::tryParse($data['name']);
        if ($name === null) {
            return $this->error('invalid_class_name');
        }
        if ($this->exists($year->id, $name)) {
            return $this->error('class_exists', 409);
        }
        if (($data['homeroom_user_id'] ?? null) !== null && ! $this->canBeHomeroom($data['homeroom_user_id'])) {
            return $this->error('homeroom_invalid');
        }

        $class = SchoolClass::query()->create([
            'academic_year_id' => $year->id,
            'grade' => $name->grade,
            'letter' => $name->letter,
            'shift' => $data['shift'],
            'language' => $data['language'] ?? ClassLanguage::Kazakh->value,
            'homeroom_user_id' => $data['homeroom_user_id'] ?? null,
        ]);

        return response()->json(['class' => $class->load('homeroom')->toApi(0)], 201);
    }

    public function update(Request $request, SchoolClass $class): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:4'],
            'shift' => ['sometimes', Rule::in(Shift::values())],
            'language' => ['sometimes', Rule::in(ClassLanguage::values())],
            'homeroom_user_id' => ['sometimes', 'nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('name', $data)) {
            $name = ClassName::tryParse($data['name']);
            if ($name === null) {
                return $this->error('invalid_class_name');
            }
            if ($this->exists($class->academic_year_id, $name, except: $class->id)) {
                return $this->error('class_exists', 409);
            }
            $class->grade = $name->grade;
            $class->letter = $name->letter;
        }
        if (array_key_exists('homeroom_user_id', $data)) {
            if ($data['homeroom_user_id'] !== null && ! $this->canBeHomeroom($data['homeroom_user_id'])) {
                return $this->error('homeroom_invalid');
            }
            $class->homeroom_user_id = $data['homeroom_user_id'];
        }
        foreach (['shift', 'language', 'is_active'] as $field) {
            if (array_key_exists($field, $data)) {
                $class->{$field} = $data[$field];
            }
        }
        $class->save();

        $count = StudentEnrollment::query()->where('class_id', $class->id)->activeOn(today())->count();

        return response()->json(['class' => $class->load('homeroom')->toApi($count)]);
    }

    private function exists(int $yearId, ClassName $name, ?int $except = null): bool
    {
        return SchoolClass::query()
            ->where('academic_year_id', $yearId)
            ->where('grade', $name->grade)
            ->where('letter', $name->letter)
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->exists();
    }

    /** Жетекші тек жұмыс істеп тұрған аккаунт бола алады. */
    private function canBeHomeroom(int $userId): bool
    {
        return User::query()->whereKey($userId)->where('is_active', true)->exists();
    }
}
