<?php

namespace App\Services\Import;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\EnrollmentService;
use App\Support\ClassName;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Оқушылар тізімін мектеп файлынан жүктеу (жобалық құжат, 2.2-бөлім).
 * `analyze` ештеңені өзгертпейді: әр жолдың не болатынын айтады. `apply` тек «жаңа» жолдарды қосады.
 */
class StudentImport
{
    private const COLUMNS = [
        'last_name' => ['тегі', 'фамилия'],
        'first_name' => ['аты', 'имя'],
        'patronymic' => ['әкесінің аты', 'отчество'],
        'class' => ['сынып', 'класс'],
    ];

    private const MAX_LISTED = 300;

    public function __construct(private readonly EnrollmentService $enrollments) {}

    /**
     * @param  list<array{name: string, rows: array<int, list<string>>}>  $sheets
     * @return array{summary: array<string, int>, sheets: list<array<string, mixed>>, problems: list<array<string, mixed>>, other_class: list<array<string, mixed>>, new: list<array<string, mixed>>}
     */
    public function analyze(array $sheets): array
    {
        $year = AcademicYear::current();
        $classes = $year === null ? collect() : SchoolClass::query()->where('academic_year_id', $year->id)->get()
            ->keyBy(static fn (SchoolClass $c): string => $c->name());

        // Қазір мектепте оқып жүрген оқушылар: «аты-жөн кілті» => сынып атауы.
        $enrolled = [];
        $today = CarbonImmutable::today();
        StudentEnrollment::query()->with(['student', 'schoolClass'])->activeOn($today)
            ->whereIn('class_id', $classes->pluck('id'))
            ->each(function (StudentEnrollment $e) use (&$enrolled): void {
                $enrolled[self::personKey($e->student->last_name, $e->student->first_name, $e->student->patronymic)][] = $e->schoolClass->name();
            });

        $summary = ['new' => 0, 'exists' => 0, 'other_class' => 0, 'errors' => 0];
        $sheetReports = [];
        $problems = [];
        $otherClass = [];
        $new = [];
        $seenInFile = [];

        foreach ($sheets as $sheet) {
            if ($sheet['rows'] === []) {
                continue;
            }
            $report = ['name' => $sheet['name'], 'new' => 0, 'exists' => 0, 'other_class' => 0, 'errors' => 0, 'error' => null];
            $header = SpreadsheetReader::findHeader($sheet['rows'], self::COLUMNS, ['last_name', 'first_name', 'class']);
            if ($header === null) {
                $report['error'] = 'header_not_found';
                $summary['errors']++;
                $sheetReports[] = $report;

                continue;
            }
            [$headerRow, $map] = $header;

            foreach ($sheet['rows'] as $rowNumber => $cells) {
                if ($rowNumber <= $headerRow) {
                    continue;
                }
                $last = Student::cleanName($cells[$map['last_name']] ?? '');
                $first = Student::cleanName($cells[$map['first_name']] ?? '');
                $patronymic = isset($map['patronymic']) ? Student::cleanName($cells[$map['patronymic']] ?? '') : null;
                $classText = $cells[$map['class']] ?? '';
                if ($last === null && $first === null && $classText === '') {
                    continue; // тек «№» толтырылған бос жол
                }

                $row = ['sheet' => $sheet['name'], 'row' => $rowNumber, 'last_name' => $last, 'first_name' => $first, 'patronymic' => $patronymic, 'class' => $classText];
                $code = $this->rowError($last, $first, $patronymic, $classText, $classes);
                $key = self::personKey((string) $last, (string) $first, $patronymic);
                $className = ClassName::tryParse($classText);

                if ($code === null && isset($seenInFile[$key.'|'.$className])) {
                    $code = 'duplicate_in_file';
                }
                if ($code !== null) {
                    $report['errors']++;
                    $summary['errors']++;
                    $problems[] = $row + ['code' => $code];

                    continue;
                }
                $seenInFile[$key.'|'.$className] = true;

                $current = $enrolled[$key] ?? [];
                if (in_array((string) $className, $current, true)) {
                    $report['exists']++;
                    $summary['exists']++;
                } elseif ($current !== []) {
                    // Өзі көшірілмейді: аттас басқа оқушы болуы да мүмкін. Шешімді әкімші қабылдайды.
                    $report['other_class']++;
                    $summary['other_class']++;
                    $otherClass[] = $row + ['current_class' => implode(', ', $current)];
                } else {
                    $report['new']++;
                    $summary['new']++;
                    $new[] = $row + ['class_id' => $classes[(string) $className]->id];
                }
            }
            $sheetReports[] = $report;
        }

        return [
            'summary' => $summary,
            'sheets' => $sheetReports,
            'problems' => array_slice($problems, 0, self::MAX_LISTED),
            'other_class' => array_slice($otherClass, 0, self::MAX_LISTED),
            'new' => $new,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $newRows  `analyze` қайтарған «new» тізімі
     */
    public function apply(array $newRows): int
    {
        $today = CarbonImmutable::today();
        $classes = SchoolClass::query()->findMany(array_unique(array_column($newRows, 'class_id')))->keyBy('id');

        return DB::transaction(function () use ($newRows, $classes, $today): int {
            foreach ($newRows as $row) {
                $student = Student::query()->create([
                    'last_name' => $row['last_name'],
                    'first_name' => $row['first_name'],
                    'patronymic' => $row['patronymic'],
                ]);
                $this->enrollments->enroll($student, $classes[$row['class_id']], $today);
            }

            return count($newRows);
        });
    }

    /** @param  Collection<string, SchoolClass>  $classes */
    private function rowError(?string $last, ?string $first, ?string $patronymic, string $classText, $classes): ?string
    {
        if ($last === null) {
            return 'missing_last_name';
        }
        if ($first === null) {
            return 'missing_first_name';
        }
        foreach ([$last, $first, (string) $patronymic] as $part) {
            if (mb_strlen($part) > 60) {
                return 'name_too_long';
            }
        }
        $name = ClassName::tryParse($classText);
        if ($name === null) {
            return 'invalid_class';
        }
        $class = $classes[(string) $name] ?? null;
        if ($class === null) {
            return 'class_not_found';
        }

        return $class->is_active ? null : 'class_closed';
    }

    public static function personKey(string $last, string $first, ?string $patronymic): string
    {
        return mb_strtolower($last."\x00".$first."\x00".(string) $patronymic);
    }
}
