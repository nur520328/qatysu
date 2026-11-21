<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\Shift;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\CalendarService;
use App\Support\Translit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Мектеп көлеміндегі жасанды дерек: 44 сынып, әрқайсысында 25 оқушы, 130 мұғалім.
 * Тек әзірлеу мен сынаққа арналған. Аты-жөндер тізімдерден кездейсоқ құралады,
 * нақты адамдарға қатысы жоқ. Іске қосу: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    public const STUDENTS_PER_CLASS = 25;

    public const TEACHERS = 130;

    /** Мектептің сыныптары (2026–2027). */
    public const CLASSES = [
        5 => ['А', 'Ә', 'Б', 'В'],
        6 => ['А', 'Ә', 'Б', 'В'],
        7 => ['А', 'Ә', 'Б', 'В', 'Г', 'Д', 'Е'],
        8 => ['А', 'Ә', 'Б', 'В', 'Г', 'Д', 'Е', 'Ж'],
        9 => ['А', 'Ә', 'Б', 'В', 'Г', 'Д', 'Е', 'Ж'],
        10 => ['А', 'Ә', 'Б', 'В', 'Г', 'Е', 'Ж'],
        11 => ['А', 'Ә', 'Б', 'В', 'Д', 'Е'],
    ];

    private const AFTERNOON_GRADES = [6, 7, 8];

    private const LAST = ['Сынақ', 'Жасанды', 'Ойдан', 'Үлгі', 'Мысал', 'Тексер', 'Байқау', 'Әзірлеу', 'Қиял', 'Өрнек', 'Ұшқын', 'Ізгі', 'Дерек', 'Нұсқа', 'Жоба', 'Кесте', 'Парақ', 'Тізім', 'Белгі', 'Санақ'];

    private const SUFFIX = [['ов', 'ова'], ['ев', 'ева'], ['бек', 'бекова'], ['бай', 'баева'], ['ұлы', 'қызы']];

    private const FIRST_M = ['Алмас', 'Берік', 'Дәурен', 'Ерлан', 'Жандос', 'Қанат', 'Мақсат', 'Нұрлан', 'Олжас', 'Самат', 'Тимур', 'Әлихан', 'Ұлан', 'Иса'];

    private const FIRST_F = ['Айгүл', 'Әсел', 'Гүлнар', 'Дана', 'Жанар', 'Қарлығаш', 'Мәдина', 'Нұргүл', 'Сәуле', 'Томирис', 'Ұлжан', 'Шолпан', 'Інжу', 'Камила'];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder өндірістік ортада іске қосылмайды.');
        }
        if (AcademicYear::query()->exists()) {
            throw new RuntimeException('DemoSeeder бос дерекқорға ғана арналған (migrate:fresh --seed-тен кейін).');
        }

        mt_srand(20261121); // әр іске қосуда бірдей дерек

        DB::transaction(function (): void {
            $year = AcademicYear::query()->create([
                'name' => '2026–2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-25', 'is_current' => true,
            ]);
            app(CalendarService::class)->generate($year);

            $teacherIds = $this->teachers();
            $now = now();
            $next = 0;
            foreach (self::CLASSES as $grade => $letters) {
                foreach ($letters as $letter) {
                    $class = SchoolClass::query()->create([
                        'academic_year_id' => $year->id,
                        'grade' => $grade,
                        'letter' => $letter,
                        'shift' => in_array($grade, self::AFTERNOON_GRADES, true) ? Shift::Afternoon : Shift::Morning,
                        'homeroom_user_id' => $teacherIds[$next++],
                    ]);

                    $students = [];
                    $seen = [];
                    while (count($students) < self::STUDENTS_PER_CLASS) {
                        [$last, $first, $patronymic] = $this->person();
                        if (isset($seen["$last $first $patronymic"])) {
                            continue;
                        }
                        $seen["$last $first $patronymic"] = true;
                        $students[] = ['last_name' => $last, 'first_name' => $first, 'patronymic' => $patronymic, 'created_at' => $now, 'updated_at' => $now];
                    }
                    DB::table('students')->insert($students);
                    $firstId = (int) DB::getPdo()->lastInsertId();
                    DB::table('student_enrollments')->insert(array_map(static fn (int $i): array => [
                        'student_id' => $firstId + $i, 'class_id' => $class->id, 'start_date' => '2026-09-01',
                        'end_date' => null, 'created_at' => $now, 'updated_at' => $now,
                    ], range(0, self::STUDENTS_PER_CLASS - 1)));
                }
            }
        });
    }

    /** @return list<int> */
    private function teachers(): array
    {
        $password = Hash::make('synaq-parol-1');
        $now = now();
        $rows = [];
        $logins = User::query()->pluck('login')->flip()->all();
        for ($i = 0; $i < self::TEACHERS; $i++) {
            [$last, $first, $patronymic] = $this->person();
            $base = Translit::loginBase($last, $first);
            $login = $base;
            for ($n = 2; isset($logins[$login]); $n++) {
                $login = $base.$n;
            }
            $logins[$login] = true;
            $rows[] = [
                'full_name' => "$last $first $patronymic", 'login' => $login, 'password' => $password,
                'role' => Role::Teacher->value, 'locale' => 'kk', 'must_change_password' => false, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('users')->insert($rows);
        $firstId = (int) DB::getPdo()->lastInsertId();

        return range($firstId, $firstId + self::TEACHERS - 1);
    }

    /** @return array{string, string, string} тегі, аты, әкесінің аты */
    private function person(): array
    {
        $female = mt_rand(0, 1) === 1;
        $suffix = self::SUFFIX[mt_rand(0, 3)][$female ? 1 : 0];
        $father = self::FIRST_M[mt_rand(0, count(self::FIRST_M) - 1)];
        $first = $female ? self::FIRST_F[mt_rand(0, count(self::FIRST_F) - 1)] : self::FIRST_M[mt_rand(0, count(self::FIRST_M) - 1)];

        return [self::LAST[mt_rand(0, count(self::LAST) - 1)].$suffix, $first, $father.($female ? 'қызы' : 'ұлы')];
    }
}
