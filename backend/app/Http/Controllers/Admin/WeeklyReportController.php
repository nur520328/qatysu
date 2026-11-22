<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\Reports\ReportSnapshotService;
use App\Services\Reports\WeeklyReportExport;
use App\Services\Reports\WeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Апталық есеп: қазіргі күй және бекітілген сурет. Келісім: docs/api.md. */
class WeeklyReportController extends Controller
{
    public function __construct(
        private readonly WeeklyReportService $weekly,
        private readonly ReportSnapshotService $snapshots,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $resolved = $this->resolve($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$monday, $snapshot, $report] = $resolved;
        $current = WeeklyReportService::weekStart(CarbonImmutable::today());

        return response()->json([
            'week' => [
                'from' => $monday->toDateString(),
                'to' => $monday->addDays(6)->toDateString(),
                'previous' => $monday->subWeek()->toDateString(),
                'next' => $monday->lessThan($current) ? $monday->addWeek()->toDateString() : null,
                'is_current' => $monday->equalTo($current),
            ],
            'version' => $request->query('version') === 'snapshot' ? 'snapshot' : 'live',
            'snapshot' => $snapshot === null ? null : [
                'generated_at' => $snapshot->generated_at->toIso8601String(),
                'changed_since' => $this->snapshots->changedSince($snapshot),
            ],
            'report' => $report,
        ])->header('Cache-Control', 'no-store');
    }

    public function export(Request $request, WeeklyReportExport $export): Response
    {
        $resolved = $this->resolve($request);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$monday, , $report] = $resolved;
        $withNames = $request->query('names') !== '0';
        if ($withNames) {
            // Файлда оқушылардың аты-жөні бар: кім, қашан алғаны журналға жазылады.
            Log::warning('Апталық есеп (аты-жөнімен) жүктеп алынды: '.$monday->toDateString(), ['user_id' => $request->user()?->id, 'ip' => $request->ip()]);
        } else {
            $report = WeeklyReportService::withoutNames($report);
        }
        $locale = $request->user()->locale->value;
        $name = 'qatysu-apta-'.$monday->toDateString().($withNames ? '' : '-jiyntyq').'.xlsx';

        return response($export->build($report, $locale, $request->query('version') === 'snapshot'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Сұраныстағы аптаны және көрсетілетін нұсқаны анықтайды.
     *
     * @return array{CarbonImmutable, ?Report, array<string, mixed>}|JsonResponse
     */
    private function resolve(Request $request): array|JsonResponse
    {
        $week = $request->query('week');
        if ($week === null || $week === '') {
            $day = CarbonImmutable::today();
        } elseif (is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) === 1 && checkdate((int) substr($week, 5, 2), (int) substr($week, 8, 2), (int) substr($week, 0, 4))) {
            $day = CarbonImmutable::parse($week)->startOfDay();
        } else {
            return $this->error('invalid_date');
        }
        $monday = WeeklyReportService::weekStart($day);
        if ($monday->greaterThan(CarbonImmutable::today())) {
            return $this->error('date_in_future');
        }
        if (! in_array($request->query('version'), [null, 'live', 'snapshot'], true)) {
            return $this->error('validation_failed');
        }

        $snapshot = $this->snapshots->weekly($monday);
        if ($request->query('version') === 'snapshot') {
            return $snapshot === null ? $this->error('not_found', 404) : [$monday, $snapshot, $snapshot->data];
        }

        return [$monday, $snapshot, $this->weekly->compute($monday)];
    }
}
