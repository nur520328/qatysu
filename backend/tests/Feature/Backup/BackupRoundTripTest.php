<?php

namespace Tests\Feature\Backup;

use App\Models\Setting;
use App\Models\User;
use App\Services\Backup\BackupService;
use App\Services\Backup\DatabaseDumper;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * Көшірме мен қалпына келтіру кестелерді шынымен өшіріп, қайта жасайды, сондықтан бұл тесттер
 * транзакция ішінде емес, әр жолы таза дерекқорда орындалады.
 */
class BackupRoundTripTest extends TestCase
{
    use BackupFiles, DatabaseMigrations;

    /** @return array<string, string> әр кестенің мазмұнынан есептелген бақылау сомасы */
    private function fingerprint(): array
    {
        $result = [];
        foreach (app(DatabaseDumper::class)->tables() as $table) {
            if (in_array($table, ['sessions', 'cache', 'cache_locks'], true)) {
                continue;
            }
            $result[$table] = md5(json_encode(DB::table($table)->orderBy(Schema::getColumnListing($table)[0])->get()));
        }

        return $result;
    }

    public function test_full_school_survives_backup_and_restore_unchanged(): void
    {
        $this->seed(DemoSeeder::class);
        // Қиын мәндер: тырнақша, кері қиғаш сызық, жаңа жол, нүктелі үтір, бөлгіш белгінің өзі, NULL.
        $tricky = "О'Нил \"Әлия\" \\ бір\nекі; DROP TABLE x;\n-- ;;\nүш";
        User::factory()->create(['full_name' => $tricky, 'telegram_chat_id' => null]);
        Setting::query()->updateOrCreate(['key' => 'max_lessons_per_shift'], ['value' => '8']);
        $before = $this->fingerprint();

        $backup = app(BackupService::class)->create();
        $this->assertSame(14, $backup['tables']);
        $this->assertGreaterThan(2500, $backup['rows']);

        // Көшірмеден кейінгі өзгерістер: дерек өшеді, өзгереді, артық кесте пайда болады.
        DB::table('student_enrollments')->delete();
        DB::table('students')->where('id', '<', 500)->delete();
        User::query()->where('role', 'teacher')->update(['full_name' => 'Бүлінген']);
        Schema::create('leftover', fn ($table) => $table->id());

        $this->travel(2)->seconds();
        $result = app(BackupService::class)->restore($backup['name']);

        $this->assertSame($before, $this->fingerprint());
        $this->assertSame($tricky, User::query()->where('full_name', 'like', "О'Нил%")->value('full_name'));
        $this->assertFalse(Schema::hasTable('leftover'));
        // Қалпына келтіру алдындағы (бүлінген) күй де сақталды: қате файл таңдалса, кері қайтуға болады.
        $this->assertNotNull($result['safety']);
        $this->assertNotNull(app(BackupService::class)->describe($result['safety']));
    }

    public function test_restore_command_needs_explicit_confirmation(): void
    {
        $user = User::factory()->create(['full_name' => 'Сынақ Бастапқы']);
        $backup = app(BackupService::class)->create();
        $user->update(['full_name' => 'Сынақ Өзгерген']);
        $this->travel(2)->seconds();

        $out = new BufferedOutput;
        $this->assertSame(1, Artisan::call('qatysu:restore', [], $out));
        $text = $out->fetch();
        $this->assertStringStartsWith('ЕШТЕҢЕ ӨЗГЕРТІЛГЕН ЖОҚ', $text);
        $this->assertStringContainsString($backup['name'], $text);
        $this->assertSame('Сынақ Өзгерген', $user->fresh()->full_name);

        $out = new BufferedOutput;
        $this->assertSame(0, Artisan::call('qatysu:restore', ['--yes' => true], $out));
        $text = $out->fetch();
        $this->assertStringStartsWith('ҚАЛПЫНА КЕЛТІРІЛДІ', $text);
        $this->assertLessThan(450, mb_strlen($text), $text);
        $this->assertSame('Сынақ Бастапқы', $user->fresh()->full_name);
    }

    public function test_truncated_backup_is_never_restored(): void
    {
        User::factory()->create(['full_name' => 'Сынақ Сақталған']);
        $backup = app(BackupService::class)->create();
        $path = app(BackupService::class)->path($backup['name']);

        // Жазу ортасында үзілген файл: соңғы жол (қорытынды) жоқ.
        $lines = gzfile($path);
        $gz = gzopen($path, 'wb');
        gzwrite($gz, implode('', array_slice($lines, 0, intdiv(count($lines), 2))));
        gzclose($gz);

        $this->assertNull(app(BackupService::class)->describe($backup['name']));
        $out = new BufferedOutput;
        $this->assertSame(1, Artisan::call('qatysu:restore', ['name' => $backup['name'], '--yes' => true], $out));
        $this->assertStringContainsString('табылмады не бүлінген', $out->fetch());
        $this->assertSame(1, User::query()->where('full_name', 'Сынақ Сақталған')->count());
    }

    public function test_restore_into_an_emptied_database_brings_everything_back(): void
    {
        // 2026-11-21 күнгі оқиға: кестелер жоғалды. Көшірме болса, бәрі қайтады.
        User::factory()->admin()->create(['login' => 'zavuch']);
        $backup = app(BackupService::class)->create();
        Schema::dropAllTables();

        $out = new BufferedOutput;
        $this->assertSame(0, Artisan::call('qatysu:restore', ['name' => $backup['name'], '--yes' => true], $out), $out->fetch());
        $this->assertSame(1, User::query()->where('login', 'zavuch')->count());
    }
}
