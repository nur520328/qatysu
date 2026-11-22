<?php

namespace Tests\Feature;

use App\Enums\Shift;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Duty\DutyCodeService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Мектеп көлеміндегі дерекпен (44 сынып, 1100 оқушы, 130 мұғалім) тізім беттері
 * сұраныс санын көбейтпей жұмыс істеуі тиіс: әр жолға бөлек сұраныс (N+1) болмауы керек.
 */
class ScaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_endpoints_stay_cheap_with_full_school_data(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 10));
        $this->seed(DemoSeeder::class);
        $admin = User::factory()->admin()->create();

        $this->assertSame(44, SchoolClass::query()->count());
        $this->assertSame(1100, DB::table('students')->count());

        $classes = $this->measure(fn () => $this->actingAs($admin)->getJson('/api/admin/classes')->assertOk());
        $this->assertCount(44, $classes['response']->json('classes'));
        $this->assertSame(1100, array_sum(array_column($classes['response']->json('classes'), 'student_count')));

        $users = $this->measure(fn () => $this->actingAs($admin)->getJson('/api/admin/users')->assertOk());
        $this->assertCount(131, $users['response']->json('users'));
        $this->assertCount(44, array_filter($users['response']->json('users'), static fn (array $u): bool => $u['homeroom_classes'] !== []));

        $class = SchoolClass::query()->where('grade', 8)->where('letter', 'Ж')->firstOrFail();
        $roster = $this->measure(fn () => $this->actingAs($admin)->getJson("/api/admin/classes/{$class->id}/students")->assertOk());
        $this->assertCount(25, $roster['response']->json('students'));

        $teacher = User::query()->findOrFail($class->homeroom_user_id);
        $homeroom = $this->measure(fn () => $this->actingAs($teacher)->getJson('/api/teacher/homeroom')->assertOk());
        $this->assertCount(25, $homeroom['response']->json('classes.0.students'));

        foreach (['classes' => $classes, 'users' => $users, 'roster' => $roster, 'homeroom' => $homeroom] as $name => $result) {
            $this->assertLessThanOrEqual(10, $result['queries'], "$name: сұраныс тым көп ({$result['queries']})");
            $this->assertLessThan(500, $result['ms'], "$name: тым баяу ({$result['ms']} мс)");
        }
    }

    public function test_duty_round_stays_cheap_with_full_school_data(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 8, 10));
        $this->seed(DemoSeeder::class);
        $admin = User::factory()->admin()->create();
        [, $plain] = app(DutyCodeService::class)->issue(CarbonImmutable::today(), Shift::Morning, $admin);

        $login = $this->postJson('/api/duty/login', ['code' => $plain])->assertOk();
        $this->withCredentials()->withCookie(config('session.cookie'), $login->getCookie(config('session.cookie'))->getValue());
        $this->assertCount(25, $login->json('classes')); // 5, 9, 10, 11-сыныптар

        $class = SchoolClass::query()->where('grade', 9)->where('letter', 'Ж')->firstOrFail();
        $roster = $this->measure(fn () => $this->getJson("/api/duty/classes/{$class->id}")->assertOk());
        $absent = array_slice(array_column($roster['response']->json('students'), 'id'), 0, 3);
        $body = ['client_uuid' => (string) Str::uuid(), 'absent_student_ids' => $absent];

        $save = $this->measure(fn () => $this->putJson("/api/duty/classes/{$class->id}/round", $body)->assertOk());
        $edit = $this->measure(fn () => $this->putJson("/api/duty/classes/{$class->id}/round", ['absent_student_ids' => [$absent[0]], 'revision' => 1] + $body)->assertOk());
        $state = $this->measure(fn () => $this->getJson('/api/duty/session')->assertOk());
        $state['response']->assertJsonPath('classes.'.array_search($class->id, array_column($state['response']->json('classes'), 'id'), true).'.round.absent_count', 1);

        $all = $this->measure(fn () => $this->getJson('/api/duty/rosters')->assertOk());
        $this->assertCount(25, $all['response']->json('rosters'));
        $this->assertSame(625, array_sum(array_map('count', array_column($all['response']->json('rosters'), 'students'))));
        $this->assertLessThan(120_000, strlen($all['response']->getContent()), 'тізімдер жауабы тым үлкен: '.strlen($all['response']->getContent()));

        foreach (['roster' => [$roster, 12], 'save' => [$save, 20], 'edit' => [$edit, 25], 'state' => [$state, 12], 'rosters' => [$all, 12]] as $name => [$result, $limit]) {
            $this->assertLessThanOrEqual($limit, $result['queries'], "$name: сұраныс тым көп ({$result['queries']})");
            $this->assertLessThan(500, $result['ms'], "$name: тым баяу ({$result['ms']} мс)");
        }
    }

    public function test_daily_summary_stays_cheap_when_every_class_is_surveyed(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 19, 0));
        $this->seed(DemoSeeder::class);
        $admin = User::factory()->admin()->create();

        // Ауыр күн: 44 сыныптың бәрі аралынған, әр сыныпта 3 оқушы жоқ, 1 кешіккен, 1 кеткен.
        $records = [];
        foreach (SchoolClass::query()->get() as $class) {
            $roundId = DB::table('attendance_rounds')->insertGetId([
                'class_id' => $class->id, 'date' => '2026-11-20', 'shift' => $class->shift->value, 'client_uuid' => (string) Str::uuid(),
                'revision' => 1, 'received_late' => false, 'duty_done' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $students = DB::table('student_enrollments')->where('class_id', $class->id)->orderBy('student_id')->pluck('student_id');
            foreach ($students as $i => $studentId) {
                $records[] = [
                    'round_id' => $roundId, 'student_id' => $studentId, 'round_status' => $i < 4 ? 'absent' : 'present', 'round_status_source' => 'duty',
                    'arrived_lesson' => $i === 3 ? 2 : null, 'left_lesson' => $i === 4 ? 5 : null, 'absence_reason' => $i === 0 ? 'sick' : null,
                    'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('attendance_records')->insert($chunk);
        }

        $summary = $this->measure(fn () => $this->actingAs($admin)->getJson('/api/admin/daily-summary')->assertOk());
        $shifts = $summary['response']->json('shifts');
        $this->assertSame([44, 44, 1100, 0, 132, 44, 44], [
            array_sum(array_column($shifts, 'classes_total')), array_sum(array_column($shifts, 'classes_surveyed')), array_sum(array_column($shifts, 'students')),
            array_sum(array_column($shifts, 'unmarked')), array_sum(array_column($shifts, 'absent')), array_sum(array_column($shifts, 'late')), array_sum(array_column($shifts, 'left')),
        ]);
        $this->assertSame(88, array_sum(array_column(array_column($shifts, 'absent_by_reason'), 'pending')));

        // Сұраныс саны тұрақты (сынып не оқушы санына тәуелді емес): сессия, күнтізбе, баптау, тізімдер.
        $this->assertLessThanOrEqual(20, $summary['queries'], "жиынтық: сұраныс тым көп ({$summary['queries']})");
        $this->assertLessThan(500, $summary['ms'], "жиынтық: тым баяу ({$summary['ms']} мс)");
        $this->assertLessThan(80_000, strlen($summary['response']->getContent()), 'жиынтық жауабы тым үлкен: '.strlen($summary['response']->getContent()));
    }

    public function test_weekly_report_stays_cheap_for_a_full_school_week(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 19, 0));
        $this->seed(DemoSeeder::class);
        $admin = User::factory()->admin()->create();

        // Толық апта: 44 сынып × 5 күн, әр сынып-күнде 2 оқушы жоқ (1 себепсіз), 1 кешіккен, 1 кеткен.
        $records = [];
        foreach (SchoolClass::query()->get() as $class) {
            $students = DB::table('student_enrollments')->where('class_id', $class->id)->orderBy('student_id')->pluck('student_id');
            foreach (['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'] as $date) {
                $roundId = DB::table('attendance_rounds')->insertGetId([
                    'class_id' => $class->id, 'date' => $date, 'shift' => $class->shift->value, 'client_uuid' => (string) Str::uuid(),
                    'revision' => 1, 'received_late' => false, 'duty_done' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($students as $i => $studentId) {
                    $records[] = [
                        'round_id' => $roundId, 'student_id' => $studentId, 'round_status' => $i < 3 ? 'absent' : 'present', 'round_status_source' => 'duty',
                        'arrived_lesson' => $i === 2 ? 2 : null, 'left_lesson' => $i === 3 ? 5 : null, 'absence_reason' => $i === 0 ? 'unexcused' : null,
                        'created_at' => now(), 'updated_at' => now(),
                    ];
                }
            }
        }
        foreach (array_chunk($records, 500) as $chunk) {
            DB::table('attendance_records')->insert($chunk);
        }

        $weekly = $this->measure(fn () => $this->actingAs($admin)->getJson('/api/admin/reports/weekly')->assertOk());
        $totals = $weekly['response']->json('report.totals');
        $this->assertEquals([220, 220, 5500, 440, 92.0, 220, 220, 220], [
            $totals['class_days_expected'], $totals['class_days_surveyed'], $totals['marked'], $totals['absent'], $totals['attendance_pct'],
            $totals['unexcused'], $totals['late'], $totals['left'],
        ]);
        // 88 оқушының себепсіз не анықталмаған күні бар, бірақ тізім 50-мен шектеледі.
        $this->assertSame([50, 88], [count($weekly['response']->json('report.students')), $weekly['response']->json('report.students_total')]);

        $this->assertLessThanOrEqual(20, $weekly['queries'], "апталық есеп: сұраныс тым көп ({$weekly['queries']})");
        $this->assertLessThan(1500, $weekly['ms'], "апталық есеп: тым баяу ({$weekly['ms']} мс)");

        $export = $this->measure(fn () => $this->actingAs($admin)->get('/api/admin/reports/weekly/export')->assertOk());
        $this->assertLessThan(3000, $export['ms'], "апталық есептің Excel файлы: тым баяу ({$export['ms']} мс)");
        $this->assertLessThan(200_000, strlen($export['response']->getContent()));
    }

    public function test_full_school_student_file_imports_without_per_row_queries(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 10));
        $this->seed(DemoSeeder::class);
        $admin = User::factory()->admin()->create();
        // Бір сыныптың оқушылары өшіріледі: файлда олар «жаңа», қалған 1075-і «бұрыннан бар» болады.
        $empty = SchoolClass::query()->where('grade', 5)->where('letter', 'А')->firstOrFail();
        DB::table('students')->whereIn('id', DB::table('student_enrollments')->where('class_id', $empty->id)->pluck('student_id'))->delete();

        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach (SchoolClass::query()->get() as $class) {
            $rows = [['№', 'Тегі', 'Аты', 'Әкесінің аты', 'Оқу тілі', 'Сынып']];
            if ($class->id === $empty->id) {
                foreach (range(1, 25) as $n) {
                    $rows[] = [$n, 'Жаңаов', "Оқушы$n", null, 'қазақ', $class->name()];
                }
            } else {
                foreach (DB::table('students')->join('student_enrollments', 'students.id', '=', 'student_id')->where('class_id', $class->id)->get() as $n => $row) {
                    $rows[] = [$n + 1, $row->last_name, $row->first_name, $row->patronymic, 'қазақ', $class->name()];
                }
            }
            $sheet = $book->createSheet();
            $sheet->setTitle($class->name());
            $sheet->fromArray($rows, null, 'A1', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'qatysu').'.xlsx';
        (new Xlsx($book))->save($path);
        $file = fn () => new UploadedFile($path, 'mektep.xlsx', null, null, true);

        $preview = $this->measure(fn () => $this->actingAs($admin)->post('/api/admin/import/students', ['file' => $file()], ['Accept' => 'application/json'])->assertOk());
        $preview['response']->assertJsonPath('summary', ['new' => 25, 'exists' => 1075, 'other_class' => 0, 'errors' => 0]);

        $confirm = $this->measure(fn () => $this->actingAs($admin)->post('/api/admin/import/students', ['file' => $file(), 'digest' => $preview['response']->json('digest')], ['Accept' => 'application/json'])->assertOk());
        $confirm['response']->assertJsonPath('imported', 25);
        @unlink($path);

        $this->assertSame(1100, DB::table('students')->count());
        $this->assertLessThanOrEqual(20, $preview['queries'], "алдын ала қарау: сұраныс тым көп ({$preview['queries']})");
        $this->assertLessThanOrEqual(150, $confirm['queries'], "растау: сұраныс тым көп ({$confirm['queries']})");
        $this->assertLessThan(5000, $preview['ms'] + $confirm['ms'], 'жүктеу тым баяу');
    }

    /** @return array{response: TestResponse, queries: int, ms: int} */
    private function measure(callable $call): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $response = $call();
        $ms = (int) ((hrtime(true) - $start) / 1_000_000);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return ['response' => $response, 'queries' => $queries, 'ms' => $ms];
    }
}
