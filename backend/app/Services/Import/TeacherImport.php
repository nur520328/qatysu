<?php

namespace App\Services\Import;

use App\Enums\Role;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\ClassName;
use App\Support\OneTimePassword;
use App\Support\Translit;
use Illuminate\Support\Facades\DB;

/**
 * Мұғалімдер тізімін үлгі файлдан жүктеу (`docs/үлгілер/mugalimder-ulgi.xlsx`).
 * `analyze` ештеңені өзгертпейді; `apply` аккаунттарды ашып, бір реттік парольдерді бір-ақ рет қайтарады.
 */
class TeacherImport
{
    private const COLUMNS = [
        'number' => ['№', 'n'],
        'last_name' => ['тегі', 'фамилия'],
        'first_name' => ['аты', 'имя'],
        'patronymic' => ['әкесінің аты', 'отчество'],
        'homeroom' => ['жетекші', 'классный', 'сынып'],
        'login' => ['логин', 'login'],
    ];

    public const LOGIN_PATTERN = '/\A[a-z0-9]+(\.[a-z0-9]+)*\z/';

    /**
     * @param  list<array{name: string, rows: array<int, list<string>>}>  $sheets
     * @return array{summary: array<string, int>, error: string|null, new: list<array<string, mixed>>, problems: list<array<string, mixed>>}
     */
    public function analyze(array $sheets): array
    {
        $summary = ['new' => 0, 'exists' => 0, 'errors' => 0, 'warnings' => 0];

        $found = null;
        foreach ($sheets as $sheet) {
            $header = SpreadsheetReader::findHeader($sheet['rows'], self::COLUMNS, ['last_name', 'first_name']);
            if ($header !== null) {
                $found = [$sheet, $header];
                break;
            }
        }
        if ($found === null) {
            return ['summary' => $summary, 'error' => 'header_not_found', 'new' => [], 'problems' => []];
        }
        [$sheet, [$headerRow, $map]] = $found;

        $year = AcademicYear::current();
        $classes = $year === null ? collect() : SchoolClass::query()->where('academic_year_id', $year->id)->get()
            ->keyBy(static fn (SchoolClass $c): string => $c->name());
        $existingNames = User::query()->pluck('full_name')->map(static fn (string $n): string => mb_strtolower($n))->flip();
        $takenLogins = User::query()->pluck('login')->flip()->all();
        $homeroomInFile = [];
        $new = [];
        $problems = [];

        foreach ($sheet['rows'] as $rowNumber => $cells) {
            if ($rowNumber <= $headerRow) {
                continue;
            }
            // Үлгі жол: № бағанында «үлгі» деп тұрады.
            if (isset($map['number']) && mb_strtolower($cells[$map['number']] ?? '') === 'үлгі') {
                continue;
            }
            $last = Student::cleanName($cells[$map['last_name']] ?? '');
            $first = Student::cleanName($cells[$map['first_name']] ?? '');
            $patronymic = isset($map['patronymic']) ? Student::cleanName($cells[$map['patronymic']] ?? '') : null;
            $classText = isset($map['homeroom']) ? ($cells[$map['homeroom']] ?? '') : '';
            $loginText = isset($map['login']) ? mb_strtolower($cells[$map['login']] ?? '') : '';
            if ($last === null && $first === null && $classText === '' && $loginText === '') {
                continue;
            }

            $fullName = trim(implode(' ', array_filter([$last, $first, $patronymic])));
            $row = ['row' => $rowNumber, 'full_name' => $fullName, 'homeroom' => $classText === '' ? null : $classText];
            $fail = function (string $code) use (&$problems, &$summary, $row): void {
                $problems[] = $row + ['code' => $code];
                $summary['errors']++;
            };

            if ($last === null) {
                $fail('missing_last_name');

                continue;
            }
            if ($first === null) {
                $fail('missing_first_name');

                continue;
            }
            if (mb_strlen($fullName) > 150) {
                $fail('name_too_long');

                continue;
            }
            if (isset($existingNames[mb_strtolower($fullName)])) {
                $summary['exists']++;

                continue;
            }

            if ($loginText !== '') {
                if (strlen($loginText) < 3 || strlen($loginText) > 60 || preg_match(self::LOGIN_PATTERN, $loginText) !== 1) {
                    $fail('invalid_login');

                    continue;
                }
                if (isset($takenLogins[$loginText])) {
                    $fail('login_taken');

                    continue;
                }
                $login = $loginText;
            } else {
                $login = self::freeLogin(Translit::loginBase($last, $first), $takenLogins);
                if ($login === null) {
                    $fail('invalid_login');

                    continue;
                }
            }

            $warning = null;
            $classId = null;
            if ($classText !== '') {
                $name = ClassName::tryParse($classText);
                $class = $name === null ? null : ($classes[(string) $name] ?? null);
                if ($name === null) {
                    $fail('invalid_class');

                    continue;
                }
                if ($class === null) {
                    $fail('class_not_found');

                    continue;
                }
                if (! $class->is_active) {
                    $fail('class_closed');

                    continue;
                }
                if (isset($homeroomInFile[$class->id])) {
                    $fail('duplicate_homeroom_in_file');

                    continue;
                }
                if ($class->homeroom_user_id !== null) {
                    // Бар жетекші өздігінен ауыстырылмайды: аккаунт ашылады, жетекшілікті әкімші өзі шешеді.
                    $warning = 'homeroom_taken';
                    $summary['warnings']++;
                } else {
                    $classId = $class->id;
                    $homeroomInFile[$class->id] = true;
                }
            }

            $takenLogins[$login] = true;
            $existingNames[mb_strtolower($fullName)] = true;
            $summary['new']++;
            $new[] = $row + ['login' => $login, 'class_id' => $classId, 'warning' => $warning];
        }

        return ['summary' => $summary, 'error' => null, 'new' => $new, 'problems' => $problems];
    }

    /**
     * @param  list<array<string, mixed>>  $newRows
     * @return list<array{full_name: string, login: string, password: string, homeroom: string|null}>
     */
    public function apply(array $newRows): array
    {
        return DB::transaction(function () use ($newRows): array {
            $credentials = [];
            foreach ($newRows as $row) {
                $password = OneTimePassword::generate();
                $user = User::query()->create([
                    'full_name' => $row['full_name'],
                    'login' => $row['login'],
                    'password' => $password,
                    'role' => Role::Teacher,
                    'must_change_password' => true,
                ]);
                if ($row['class_id'] !== null) {
                    SchoolClass::query()->whereKey($row['class_id'])->whereNull('homeroom_user_id')
                        ->update(['homeroom_user_id' => $user->id]);
                }
                $credentials[] = [
                    'full_name' => $user->full_name,
                    'login' => $user->login,
                    'password' => $password,
                    'homeroom' => $row['class_id'] === null ? null : $row['homeroom'],
                ];
            }

            return $credentials;
        });
    }

    /**
     * Бос логинді табады: негіз бос емес болса, соңына 2, 3, … қосылады.
     *
     * @param  array<string, mixed>  $taken
     */
    public static function freeLogin(string $base, array $taken): ?string
    {
        if (strlen($base) < 3 || preg_match(self::LOGIN_PATTERN, $base) !== 1) {
            return null;
        }
        $base = substr($base, 0, 56);
        if (! isset($taken[$base])) {
            return $base;
        }
        for ($n = 2; $n < 1000; $n++) {
            if (! isset($taken[$base.$n])) {
                return $base.$n;
            }
        }

        return null;
    }
}
