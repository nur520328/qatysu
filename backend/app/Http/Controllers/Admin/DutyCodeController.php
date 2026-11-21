<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Shift;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Duty\SessionController;
use App\Models\AttendanceRound;
use App\Models\DutyCode;
use App\Models\SchoolClass;
use App\Services\Duty\DutyCodeService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/** Завуч кезекші кодын береді, жояды және аралау барысын көреді. Келісім: docs/api.md. */
class DutyCodeController extends Controller
{
    public function __construct(private readonly DutyCodeService $codes) {}

    public function index(): JsonResponse
    {
        $days = array_map(fn (CarbonImmutable $date): array => [
            'date' => $date->toDateString(),
            'is_today' => $date->isToday(),
            'shifts' => array_map(fn (Shift $shift): array => $this->shift($date, $shift), Shift::cases()),
        ], $this->codes->issuableDates());

        return response()->json([
            'days' => $days,
            // Кодты теріп табуға әрекет жасалып жатса, завуч оны көруі тиіс.
            'login_blocked' => RateLimiter::tooManyAttempts(SessionController::GLOBAL_KEY, SessionController::MAX_FAILURES_GLOBAL),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'shift' => ['required', Rule::in(Shift::values())],
        ]);
        $date = CarbonImmutable::parse($data['date']);
        $shift = Shift::from($data['shift']);

        try {
            [, $plain] = $this->codes->issue($date, $shift, $request->user());
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }

        // Ашық код тек осы жауапта болады; дерекқорда хеші ғана сақталады.
        return response()->json(['code' => $plain, 'shift' => $this->shift($date, $shift)], 201);
    }

    public function destroy(DutyCode $code): JsonResponse
    {
        $this->codes->revoke($code);

        return response()->json(['shift' => $this->shift(CarbonImmutable::parse($code->date), $code->shift)]);
    }

    /** @return array<string, mixed> */
    private function shift(CarbonImmutable $date, Shift $shift): array
    {
        $code = $this->codes->active($date, $shift);
        $classIds = SchoolClass::query()->where('shift', $shift)->where('is_active', true)
            ->whereHas('academicYear', fn ($q) => $q->where('is_current', true))->pluck('id');

        return [
            'shift' => $shift->value,
            'ends_at' => $this->codes->shiftEnd($date, $shift)->toIso8601String(),
            'ended' => $this->codes->shiftEnd($date, $shift)->isPast(),
            'classes_total' => $classIds->count(),
            'classes_done' => AttendanceRound::query()->whereIn('class_id', $classIds)->whereDate('date', $date->toDateString())->count(),
            'code' => $code === null ? null : [
                'id' => $code->id,
                'issued_at' => $code->created_at->toIso8601String(),
                'issued_by' => $code->issuer->full_name,
                'devices' => $code->sessions()->count(),
            ],
        ];
    }
}
