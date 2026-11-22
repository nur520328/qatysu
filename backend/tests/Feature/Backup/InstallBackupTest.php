<?php

namespace Tests\Feature\Backup;

use App\Console\Commands\Install;
use App\Models\User;
use App\Services\Backup\BackupService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** Қызыл сызық: сақтық көшірмесіз кесте өзгертілмейді. */
class InstallBackupTest extends TestCase
{
    use BackupFiles, DatabaseMigrations;

    private string $extraMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org*' => Http::response('', 302)]);
        @unlink(Install::historyPath());

        // Келесі нұсқада келетін жаңа миграцияның үлгісі.
        $this->extraMigrations = sys_get_temp_dir().'/qatysu-migrations-'.uniqid();
        File::makeDirectory($this->extraMigrations);
        File::put($this->extraMigrations.'/2099_01_01_000000_create_probe_table.php', <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\Schema;
            return new class extends Migration {
                public function up(): void { Schema::create('probe', fn ($table) => $table->id()); }
                public function down(): void { Schema::dropIfExists('probe'); }
            };
            PHP);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('probe');
        File::deleteDirectory($this->extraMigrations);
        @unlink(Install::historyPath());
        parent::tearDown();
    }

    /** @return array{int, string} */
    private function install(): array
    {
        $buffer = new BufferedOutput;
        $exit = Artisan::call('qatysu:install', [], $buffer);

        return [$exit, $buffer->fetch()];
    }

    public function test_update_with_new_migrations_backs_up_first_and_records_history(): void
    {
        User::factory()->admin()->create();
        app('migrator')->path($this->extraMigrations);

        [$exit, $output] = $this->install();

        $this->assertSame(0, $exit, $output);
        $backups = app(BackupService::class)->all();
        $this->assertCount(1, $backups);
        $this->assertStringContainsString('Жаңарту алдындағы көшірме: '.$backups[0]['name'], $output);
        $this->assertTrue(Schema::hasTable('probe'));
        $this->assertLessThan(450, mb_strlen($output), $output);

        $history = json_decode((string) file_get_contents(Install::historyPath()), true);
        $this->assertSame(['tables' => 21, 'users' => 1, 'charset' => 'utf8mb4'], $history['before']);
        $this->assertSame(1, $history['migrations_ran']);
        $this->assertSame('ok', $history['result']);

        // Жаңа миграция жоқ кезде артық көшірме жасалмайды.
        [$exit] = $this->install();
        $this->assertSame(0, $exit);
        $this->assertCount(1, app(BackupService::class)->all());
    }

    public function test_update_is_not_blocked_by_the_daily_limit_for_manual_backups(): void
    {
        // Хостингте болған жағдай: бір күнде бірнеше жаңарту мен қолмен жасалған көшірме шекке жеткізді.
        User::factory()->admin()->create();
        for ($i = 0; $i < BackupService::MAX_PER_DAY; $i++) {
            $this->travel(1)->seconds();
            app(BackupService::class)->create();
        }
        $this->travel(1)->seconds();
        app('migrator')->path($this->extraMigrations);

        [$exit, $output] = $this->install();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Жаңарту алдындағы көшірме: ', $output);
        $this->assertTrue(Schema::hasTable('probe'));
        $this->assertCount(BackupService::MAX_PER_DAY + 1, app(BackupService::class)->all());
    }

    public function test_tables_are_not_changed_when_the_backup_fails(): void
    {
        User::factory()->admin()->create();
        app('migrator')->path($this->extraMigrations);
        $this->mock(BackupService::class, fn ($mock) => $mock->shouldReceive('create')->andThrow(new RuntimeException('')));

        [$exit, $output] = $this->install();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('кестелер өзгертілген жоқ', $output);
        $this->assertStringContainsString('RuntimeException', $output); // хабары бос қатенің де түрі көрінеді
        $this->assertFalse(Schema::hasTable('probe'));
        $this->assertStringContainsString('"result":"backup_failed"', (string) file_get_contents(Install::historyPath()));
    }
}
