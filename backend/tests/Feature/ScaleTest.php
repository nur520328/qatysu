<?php

namespace Tests\Feature;

use App\Models\SchoolClass;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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
