<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Services\Reports\MonthlyReportService;
use App\Services\Reports\PeriodReport;
use App\Services\Reports\ReportExport;
use App\Services\Reports\ReportSnapshotService;
use App\Services\Reports\WeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Апталық және айлық есеп: қазіргі күй және бекітілген сурет. Келісім: docs/api.md. */
class ReportController extends Controller
{
    public function __construct(
        private readonly WeeklyReportService $weekly,
        private readonly MonthlyReportService $monthly,
        private readonly ReportSnapshotService $snapshots,
    ) {}

    public function show(Request $request, string $type): JsonResponse
    {
        $resolved = $this->resolve($request, $type);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$start, $snapshot, $report] = $resolved;
        $weekly = $type === Report::WEEKLY;
        $current = $this->start($type, CarbonImmutable::today());
        $next = $weekly ? $start->addWeek() : $start->addMonthNoOverflow();

        return response()->json([
            'period' => [
                'from' => $start->toDateString(),
                'to' => ($weekly ? $start->addDays(6) : $start->endOfMonth())->toDateString(),
                'previous' => ($weekly ? $start->subWeek() : $start->subMonthNoOverflow())->toDateString(),
                'next' => $start->lessThan($current) ? $next->toDateString() : null,
                'is_current' => $start->equalTo($current),
            ],
            'version' => $request->query('version') === 'snapshot' ? 'snapshot' : 'live',
            'snapshot' => $snapshot === null ? null : [
                'generated_at' => $snapshot->generated_at->toIso8601String(),
                'changed_since' => $this->snapshots->changedSince($snapshot),
            ],
            'report' => $report,
        ])->header('Cache-Control', 'no-store');
    }

    public function export(Request $request, string $type, ReportExport $export): Response
    {
        $resolved = $this->resolve($request, $type);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$start, , $report] = $resolved;
        $withNames = $request->query('names') !== '0';
        $label = $type === Report::WEEKLY ? 'apta-'.$start->toDateString() : 'ai-'.$start->format('Y-m');
        if ($withNames) {
            // Файлда оқушылардың аты-жөні бар: кім, қашан алғаны журналға жазылады.
            Log::warning('Есеп (аты-жөнімен) жүктеп алынды: '.$label, ['user_id' => $request->user()?->id, 'ip' => $request->ip()]);
        } else {
            $report = PeriodReport::withoutNames($report);
        }
        $name = 'qatysu-'.$label.($withNames ? '' : '-jiyntyq').'.xlsx';

        return response($export->build($report, $request->user()->locale->value, $request->query('version') === 'snapshot'), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    private function start(string $type, CarbonImmutable $day): CarbonImmutable
    {
        return $type === Report::WEEKLY ? WeeklyReportService::weekStart($day) : MonthlyReportService::monthStart($day);
    }

    /**
     * Сұраныстағы кезеңді және көрсетілетін нұсқаны анықтайды. Кезең `date` арқылы беріледі: оның ішіндегі кез келген күн.
     *
     * @return array{CarbonImmutable, ?Report, array<string, mixed>}|JsonResponse
     */
    private function resolve(Request $request, string $type): array|JsonResponse
    {
        $date = $request->query('date');
        if ($date === null || $date === '') {
            $day = CarbonImmutable::today();
        } elseif (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $day = CarbonImmutable::parse($date)->startOfDay();
        } else {
            return $this->error('invalid_date');
        }
        $start = $this->start($type, $day);
        if ($start->greaterThan(CarbonImmutable::today())) {
            return $this->error('date_in_future');
        }
        if (! in_array($request->query('version'), [null, 'live', 'snapshot'], true)) {
            return $this->error('validation_failed');
        }

        $snapshot = $this->snapshots->find($type, $start);
        if ($request->query('version') === 'snapshot') {
            return $snapshot === null ? $this->error('not_found', 404) : [$start, $snapshot, $snapshot->data];
        }

        return [$start, $snapshot, ($type === Report::WEEKLY ? $this->weekly : $this->monthly)->compute($start)];
    }
}
