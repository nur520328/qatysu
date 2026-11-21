<?php

namespace Tests\Feature\Backup;

use App\Models\User;
use App\Services\Backup\BackupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class BackupApiTest extends TestCase
{
    use BackupFiles, RefreshDatabase;

    private function fake(string $stamp): void
    {
        $service = app(BackupService::class);
        $source = $service->path($service->create()['name']);
        rename($source, $service->directory()."/qatysu-{$stamp}.sql.gz");
    }

    public function test_admin_lists_creates_and_downloads_backups(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 10));
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->getJson('/api/admin/backups')
            ->assertOk()->assertExactJson(['last_at' => null, 'stale' => true, 'backups' => []]);

        $name = $this->actingAs($admin)->postJson('/api/admin/backups')->assertCreated()
            ->assertJsonPath('backup.version', config('app.version'))->json('backup.name');
        $this->assertMatchesRegularExpression(BackupService::NAME_PATTERN, $name);

        $this->actingAs($admin)->getJson('/api/admin/backups')->assertOk()
            ->assertJsonPath('stale', false)->assertJsonPath('backups.0.name', $name);

        $download = $this->actingAs($admin)->get("/api/admin/backups/{$name}/download")->assertOk();
        $this->assertSame('application/gzip', $download->headers->get('Content-Type'));
        $this->assertStringContainsString($name, (string) $download->headers->get('Content-Disposition'));
    }

    public function test_backup_older_than_26_hours_is_reported_stale(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 10));
        $admin = User::factory()->admin()->create();
        app(BackupService::class)->create();

        $this->travelTo(CarbonImmutable::create(2026, 11, 21, 11));
        $this->actingAs($admin)->getJson('/api/admin/backups')->assertJsonPath('stale', false);
        $this->travelTo(CarbonImmutable::create(2026, 11, 21, 12, 1));
        $this->actingAs($admin)->getJson('/api/admin/backups')->assertJsonPath('stale', true);
    }

    public function test_teachers_and_guests_cannot_touch_backups(): void
    {
        $name = app(BackupService::class)->create()['name'];

        $this->getJson("/api/admin/backups/{$name}/download")->assertUnauthorized();

        $this->actingAs(User::factory()->create());
        $this->getJson('/api/admin/backups')->assertForbidden();
        $this->postJson('/api/admin/backups')->assertForbidden();
        $this->getJson("/api/admin/backups/{$name}/download")->assertForbidden();
    }

    public function test_download_accepts_only_real_backup_names(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach (['..%2F..%2F.env', 'qatysu-20261120-100000.sql.gz', '.env', 'qatysu-20261120-100000.sql.gz.part'] as $name) {
            $this->getJson("/api/admin/backups/{$name}/download")->assertNotFound();
        }
    }

    public function test_manual_backups_are_limited_per_day(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 10));
        $admin = User::factory()->admin()->create();

        for ($i = 0; $i < BackupService::MAX_PER_DAY; $i++) {
            $this->travel(1)->seconds();
            $this->actingAs($admin)->postJson('/api/admin/backups')->assertCreated();
        }
        $this->travel(1)->seconds();
        $this->actingAs($admin)->postJson('/api/admin/backups')->assertStatus(429)->assertJsonPath('code', 'backup_limit_reached');

        // Түнгі автоматты іске қосу бұл жағдайда қате деп саналмайды.
        $out = new BufferedOutput;
        $this->assertSame(0, Artisan::call('qatysu:backup', [], $out));
    }

    public function test_old_backups_are_pruned_by_the_retention_rule(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 10));
        // Бір күнде екеу (соңғысы қалады), 29 күн бұрынғы (қалады), 40 күн бұрынғы аптада екеу
        // (аптаның соңғысы қалады), 100 күн бұрынғы (өшеді).
        foreach (['20261119-020000', '20261119-230000', '20261022-023000', '20261012-023000', '20261014-023000', '20260812-023000'] as $stamp) {
            $this->fake($stamp);
        }
        file_put_contents(app(BackupService::class)->directory().'/qatysu-20261101-000000.sql.gz.part', 'x');
        touch(app(BackupService::class)->directory().'/qatysu-20261101-000000.sql.gz.part', now()->subHours(2)->getTimestamp());

        app(BackupService::class)->create();

        $names = array_column(app(BackupService::class)->all(), 'name');
        $this->assertSame([
            'qatysu-20261120-100000.sql.gz',
            'qatysu-20261119-230000.sql.gz',
            'qatysu-20261022-023000.sql.gz',
            'qatysu-20261014-023000.sql.gz',
        ], $names);
        $this->assertSame([], glob(app(BackupService::class)->directory().'/*.part'));
    }

    public function test_nightly_backup_is_scheduled(): void
    {
        $out = new BufferedOutput;
        Artisan::call('schedule:list', [], $out);
        $this->assertMatchesRegularExpression('/30\s+2\s+\*\s+\*\s+\*.*qatysu:backup/', $out->fetch());
    }
}
