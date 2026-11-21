<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Attendance\DailySummaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Завучтың күнделікті жиынтығы. Келісім: docs/api.md. */
class DailySummaryController extends Controller
{
    public function show(Request $request, DailySummaryService $summary): JsonResponse
    {
        $date = $request->query('date');
        if ($date === null || $date === '') {
            $day = CarbonImmutable::today();
        } elseif (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } else {
            return $this->error('invalid_date');
        }
        if ($day->greaterThan(CarbonImmutable::today())) {
            return $this->error('date_in_future');
        }

        // Аты-жөн мен себеп бар: аралық кэштерде сақталмауы тиіс.
        return response()->json($summary->forDate($day))->header('Cache-Control', 'no-store');
    }
}
