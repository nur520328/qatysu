<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Backup\BackupLimitReached;
use App\Services\Backup\BackupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Сақтық көшірмелер: тізім, қолмен жасау, жүктеп алу. Келісім: docs/api.md.
 * Қалпына келтіру мұнда әдейі жоқ: ол тек хостинг панелінен (qatysu:restore).
 */
class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(): JsonResponse
    {
        return response()->json($this->backups->health() + ['backups' => $this->backups->all()]);
    }

    public function store(): JsonResponse
    {
        @set_time_limit(180);
        try {
            $backup = $this->backups->create();
        } catch (BackupLimitReached) {
            return $this->error('backup_limit_reached', 429);
        } catch (Throwable) {
            return $this->error('backup_failed', 500);
        }

        return response()->json(['backup' => $backup], 201);
    }

    public function download(Request $request, string $name): BinaryFileResponse|JsonResponse
    {
        $path = $this->backups->path($name);
        if ($path === null) {
            return $this->error('not_found', 404);
        }

        // Файлда барлық оқушы мен мұғалімнің дерегі бар: кім, қашан алғаны журналға жазылады.
        Log::warning("Сақтық көшірме жүктеп алынды: {$name}", ['user_id' => $request->user()?->id, 'ip' => $request->ip()]);

        return response()->download($path, $name, ['Content-Type' => 'application/gzip', 'Cache-Control' => 'no-store']);
    }
}
