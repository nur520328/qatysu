<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\ClearData;
use App\Console\Commands\Install;
use App\Enums\Shift;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Duty\DutyCodeService;
use App\Services\Maintenance\DataResetService;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** Сынақ дерегін тазалау командасы. Дерек жасанды (DemoSeeder). */
class ClearDataTest extends TestCase
{
    use BackupFiles, DatabaseMigrations;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        @unlink(Install::historyPath());
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 8, 10));
        $this->seed(DemoSeeder::class);
        $this->admin = User::factory()->admin()->create(['login' => 'zavuch-synaq']);
        Setting::query()->updateOrCreate(['key' => 'homeroom_edit_days'], ['value' => '9']);
        $this->markDays(['2026-11-19', '2026-11-20']);
    }

    protected function tearDown(): void
    {
        @unlink(Install::historyPath());
        parent::tearDown();
    }

    /** Көрсетілген күндерге бір сыныптың аралауын, жазбаларын, тарихын, кодын және сеанстарын жасайды. */
    private function markDays(array $dates): void
    {
        $class = DB::table('classes')->first();
        $students = DB::table('student_enrollments')->where('class_id', $class->id)->pluck('student_id');
        $teacher = DB::table('users')->where('role', 'teacher')->value('id');
        foreach ($dates as $date) {
            $roundId = DB::table('attendance_rounds')->insertGetId([
                'class_id' => $class->id, 'date' => $date, 'shift' => $class->shift, 'client_uuid' => (string) Str::uuid(),
                'revision' => 1, 'received_late' => false, 'duty_done' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($students as $studentId) {
                $recordId = DB::table('attendance_records')->insertGetId([
                    'round_id' => $roundId, 'student_id' => $studentId, 'round_status' => 'present', 'round_status_source' => 'duty',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('attendance_audit')->insert([
                'record_id' => $recordId, 'field' => 'arrived_lesson', 'old_value' => null, 'new_value' => '2',
                'changed_by' => $teacher, 'changed_role' => 'teacher', 'changed_at' => now(),
            ]);
        }
        if (DB::table('duty_codes')->count() === 0) {
            [$code] = app(DutyCodeService::class)->issue(CarbonImmutable::parse('2026-11-20'), Shift::Morning, $this->admin);
            DB::table('duty_sessions')->insert(['duty_code_id' => $code->id, 'device_number' => 1, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            foreach ([[$this->admin->id, 'admin-session'], [$teacher, 'teacher-session'], [null, 'tablet-session']] as [$userId, $id]) {
                DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => now()->timestamp]);
            }
        }
    }

    /** @return array{int, string} */
    private function run_(array $options = []): array
    {
        $buffer = new BufferedOutput;
        $exit = Artisan::call('qatysu:clear-data', $options, $buffer);

        return [$exit, $buffer->fetch()];
    }

    public function test_without_confirmation_it_only_reports(): void
    {
        [$exit, $output] = $this->run_();

        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('ЕШТЕҢЕ ӨШІРІЛГЕН ЖОҚ', $output);
        $this->assertStringContainsString('оқушы 1100, сынып 44, мұғалім 130, қатысу жазбасы 50 (2 күн)', $output);
        $this->assertStringContainsString('завуч аккаунты (1)', $output);
        $this->assertSame(1100, DB::table('students')->count());
        $this->assertSame([], app(BackupService::class)->all());
    }

    public function test_confirmed_run_backs_up_then_clears_school_data_and_keeps_the_rest(): void
    {
        $calendarDays = DB::table('calendar_days')->count();

        [$exit, $output] = $this->run_(['--yes' => true]);

        $this->assertSame(0, $exit, $output);
        $this->assertStringStartsWith('ТАЗАЛАНДЫ', $output);
        foreach (DataResetService::CLEARED as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        // Қалғаны: завуч (паролі де), баптау, оқу жылы, күнтізбе, завучтың сеансы.
        $this->assertSame(['zavuch-synaq'], DB::table('users')->pluck('login')->all());
        $this->assertSame($this->admin->password, DB::table('users')->value('password'));
        $this->assertSame(9, Setting::int('homeroom_edit_days'));
        $this->assertSame(1, DB::table('academic_years')->count());
        $this->assertSame($calendarDays, DB::table('calendar_days')->count());
        $this->assertSame(['admin-session'], DB::table('sessions')->pluck('id')->all());

        // Көшірме өшіруден бұрын жасалған: одан бәрі қайтарылады.
        $backups = app(BackupService::class)->all();
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('Тазалау алдындағы көшірме: '.$backups[0]['name'], $output);
        $this->assertStringContainsString('"action":"clear-data"', (string) file_get_contents(Install::historyPath()));
        $this->assertStringContainsString('"result":"cleared"', (string) file_get_contents(Install::historyPath()));

        $this->travel(1)->seconds();
        app(BackupService::class)->restore($backups[0]['name']);
        $this->assertSame([1100, 44, 131, 50], [DB::table('students')->count(), DB::table('classes')->count(), DB::table('users')->count(), DB::table('attendance_records')->count()]);
    }

    public function test_nothing_is_deleted_when_the_backup_fails(): void
    {
        $this->mock(BackupService::class, fn ($mock) => $mock->shouldReceive('create')->andThrow(new RuntimeException('')));

        [$exit, $output] = $this->run_(['--yes' => true]);

        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('ТОҚТАТЫЛДЫ', $output);
        $this->assertStringContainsString('RuntimeException', $output);
        $this->assertSame([1100, 50], [DB::table('students')->count(), DB::table('attendance_records')->count()]);
    }

    public function test_it_refuses_when_the_data_looks_like_real_use(): void
    {
        $dates = array_map(static fn (int $day): string => sprintf('2026-10-%02d', $day), range(1, ClearData::TEST_DAYS_LIMIT - 1));
        $this->markDays($dates); // барлығы 11 күн

        [$exit, $output] = $this->run_(['--yes' => true]);
        $this->assertSame(1, $exit);
        $this->assertStringStartsWith('ТОҚТАТЫЛДЫ', $output);
        $this->assertStringContainsString('11 түрлі күнге', $output);
        $this->assertSame(1100, DB::table('students')->count());
        $this->assertSame([], app(BackupService::class)->all());

        [$exit] = $this->run_(['--yes' => true, '--even-if-in-use' => true]);
        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('students')->count());
    }

    public function test_every_table_is_either_cleared_or_kept(): void
    {
        $tables = array_map(static fn (object $row): string => (string) array_values((array) $row)[0], DB::select('show tables'));
        sort($tables);
        $known = [...DataResetService::CLEARED, ...DataResetService::KEPT];
        sort($known);

        $this->assertSame($tables, $known, 'Жаңа кесте DataResetService::CLEARED не KEPT тізіміне жазылмаған.');
    }
}
