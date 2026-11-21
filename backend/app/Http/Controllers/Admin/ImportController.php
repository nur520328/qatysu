<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Import\SpreadsheetReader;
use App\Services\Import\StudentImport;
use App\Services\Import\TeacherImport;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * docs/api.md, «Excel-ден жүктеу». Екі қадам: алдын ала қарау (ештеңе өзгермейді) және растау.
 * Растауда сол файл қайта жіберіледі; `digest` оның алдын ала қаралған файлмен бірдей екенін
 * тексереді. Файл серверде сақталмайды.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly StudentImport $students,
        private readonly TeacherImport $teachers,
    ) {}

    public function students(Request $request): JsonResponse
    {
        $loaded = $this->load($request);
        if ($loaded instanceof JsonResponse) {
            return $loaded;
        }
        [$sheets, $digest, $confirm] = $loaded;

        $analysis = $this->students->analyze($sheets);
        $imported = $confirm ? $this->students->apply($analysis['new']) : null;

        return response()->json([
            'digest' => $digest,
            'imported' => $imported,
            'summary' => $analysis['summary'],
            'sheets' => $analysis['sheets'],
            'problems' => $analysis['problems'],
            'other_class' => $analysis['other_class'],
        ]);
    }

    public function teachers(Request $request): JsonResponse
    {
        $loaded = $this->load($request);
        if ($loaded instanceof JsonResponse) {
            return $loaded;
        }
        [$sheets, $digest, $confirm] = $loaded;

        $analysis = $this->teachers->analyze($sheets);
        if ($analysis['error'] !== null) {
            return $this->error($analysis['error']);
        }
        $credentials = $confirm ? $this->teachers->apply($analysis['new']) : null;

        return response()->json([
            'digest' => $digest,
            'summary' => $analysis['summary'],
            'new' => array_map(static fn (array $row): array => [
                'row' => $row['row'], 'full_name' => $row['full_name'], 'login' => $row['login'],
                'homeroom' => $row['class_id'] === null ? null : $row['homeroom'], 'warning' => $row['warning'],
            ], $analysis['new']),
            'problems' => $analysis['problems'],
            // Бір реттік парольдер тек осы жауапта, бір-ақ рет беріледі; серверде ашық түрде сақталмайды.
            'credentials' => $credentials,
        ]);
    }

    /**
     * @return array{list<array{name: string, rows: array<int, list<string>>}>, string, bool}|JsonResponse
     */
    private function load(Request $request): array|JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:5120'],
            'digest' => ['sometimes', 'nullable', 'string', 'size:64'],
        ]);
        $file = $request->file('file');
        if (mb_strtolower((string) $file->getClientOriginalExtension()) !== 'xlsx') {
            return $this->error('file_not_xlsx');
        }

        $digest = hash_file('sha256', $file->getRealPath());
        $confirm = $request->filled('digest');
        if ($confirm && ! hash_equals($digest, (string) $request->input('digest'))) {
            return $this->error('file_changed', 409);
        }

        try {
            return [$this->reader->read($file->getRealPath()), $digest, $confirm];
        } catch (DomainException $e) {
            return $this->error($e->getMessage());
        }
    }
}
