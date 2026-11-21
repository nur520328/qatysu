<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\CalendarDay;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** docs/api.md, «Оқу жылы және күнтізбе». */
class AcademicYearController extends Controller
{
    private const MAX_DAYS = 400;

    public function __construct(private readonly CalendarService $calendar) {}

    public function index(): JsonResponse
    {
        $years = AcademicYear::query()->orderByDesc('start_date')->get();

        return response()->json(['years' => $years->map->toApi()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:30'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d'],
        ]);
        $start = CarbonImmutable::parse($data['start_date']);
        $end = CarbonImmutable::parse($data['end_date']);

        if ($end->lte($start) || $start->diffInDays($end) > self::MAX_DAYS) {
            return $this->error('invalid_range');
        }
        if (AcademicYear::query()->where('name', $data['name'])->exists()) {
            return $this->error('name_taken', 409);
        }
        $overlaps = AcademicYear::query()
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
        if ($overlaps) {
            return $this->error('dates_overlap', 409);
        }

        $year = DB::transaction(function () use ($data): AcademicYear {
            // Алғашқы оқу жылы бірден ағымдағы болады; кейінгілерін әкімші өзі ауыстырады.
            $year = AcademicYear::query()->create($data + ['is_current' => ! AcademicYear::query()->exists()]);
            $this->calendar->generate($year);

            return $year;
        });

        return response()->json(['year' => $year->toApi()], 201);
    }

    public function makeCurrent(AcademicYear $year): JsonResponse
    {
        DB::transaction(function () use ($year): void {
            AcademicYear::query()->where('is_current', true)->update(['is_current' => false]);
            $year->forceFill(['is_current' => true])->save();
        });

        return response()->json(['year' => $year->toApi()]);
    }

    public function calendar(AcademicYear $year): JsonResponse
    {
        $days = CalendarDay::query()->where('academic_year_id', $year->id)->orderBy('date')->get();

        return response()->json([
            'year' => $year->toApi(),
            'days' => $days->map->toApi()->all(),
            'school_day_count' => $days->where('is_school_day', true)->count(),
        ]);
    }

    public function updateCalendar(Request $request, AcademicYear $year): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'is_school_day' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:100'],
        ]);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        if ($to->lt($from)
            || $from->lt(CarbonImmutable::parse($year->start_date))
            || $to->gt(CarbonImmutable::parse($year->end_date))) {
            return $this->error('invalid_range');
        }

        $changed = $this->calendar->setRange($year, $from, $to, (bool) $data['is_school_day'], $data['note'] ?? null);

        return response()->json(['changed' => $changed]);
    }
}
