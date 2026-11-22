<?php

namespace App\Http\Controllers\Homeroom;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Services\Reports\ClassMonthService;
use App\Services\Reports\MonthlyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Сынып жетекшісінің айлық қорытындысы (завуч кез келген сыныптікін көреді). Келісім: docs/api.md. */
class MonthController extends Controller
{
    public function show(Request $request, SchoolClass $class, ClassMonthService $months): JsonResponse
    {
        if (! $request->user()->maySeeClass($class)) {
            return $this->error('forbidden', 403);
        }
        $date = $request->query('date');
        if ($date === null || $date === '') {
            $day = CarbonImmutable::today();
        } elseif (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } else {
            return $this->error('invalid_date');
        }
        if (MonthlyReportService::monthStart($day)->greaterThan(CarbonImmutable::today())) {
            return $this->error('date_in_future');
        }

        return response()->json($months->compute($class, $day))->header('Cache-Control', 'no-store');
    }
}
