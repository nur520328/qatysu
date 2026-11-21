<?php

namespace App\Http\Controllers\Teacher;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\EnrollmentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Сынып жетекшісінің өз сыныптары мен бүгінгі тізімі (тек оқу үшін). Келісім: docs/api.md. */
class HomeroomController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $today = CarbonImmutable::today();

        $classes = array_map(function (SchoolClass $class) use ($today): array {
            $students = $this->enrollments->roster($class, $today);

            return [
                'class' => $class->toApi(count($students)),
                'students' => array_map(static fn (Student $s): array => $s->toApi($class->id), $students),
            ];
        }, $user->homeroomClasses());

        return response()->json(['date' => $today->toDateString(), 'classes' => $classes]);
    }
}
