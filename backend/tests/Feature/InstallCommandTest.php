<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['api.telegram.org*' => Http::response('', 302)]);
    }

    /**
     * Команда ішінде басқа командалар шақырылады, ал `Artisan::output()` тек соңғысының
     * шығысын береді. Сондықтан сыртқы команданың шығысы бөлек буферге жиналады.
     *
     * @param  array<string, string>  $arguments
     * @return array{int, string}
     */
    private function install(array $arguments = []): array
    {
        $buffer = new BufferedOutput;
        $exit = Artisan::call('qatysu:install', $arguments, $buffer);

        return [$exit, $buffer->fetch()];
    }

    public function test_first_run_creates_admin_and_reports_environment(): void
    {
        [$exit, $output] = $this->install(['login' => 'zavuch', 'full_name' => 'Сынақ Завуч']);

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('PHP: '.PHP_VERSION, $output);
        $this->assertStringContainsString('Дерекқор: ', $output);
        $this->assertStringContainsString('Бір реттік пароль: ', $output);
        $this->assertStringContainsString('Telegram-ға шығу: бар', $output);
        $this->assertSame(1, User::query()->count());
    }

    public function test_login_alone_is_enough_for_first_admin(): void
    {
        [$exit, $output] = $this->install(['login' => 'zavuch']);

        $this->assertSame(0, $exit, $output);
        $this->assertSame('Әкімші', User::query()->where('login', 'zavuch')->value('full_name'));
    }

    public function test_repeat_run_is_safe_and_does_not_create_second_admin(): void
    {
        User::factory()->admin()->create();

        [$exit, $output] = $this->install();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Әкімші аккаунты бар', $output);
        $this->assertStringNotContainsString('Бір реттік пароль', $output);
        $this->assertSame(1, User::query()->count());
    }

    public function test_without_admin_and_without_arguments_it_fails_clearly(): void
    {
        [$exit] = $this->install();

        $this->assertSame(1, $exit);
        $this->assertSame(0, User::query()->count());
    }

    public function test_unreachable_telegram_is_a_warning_not_a_failure(): void
    {
        Http::fake(fn () => throw new \RuntimeException('connection refused'));
        User::factory()->admin()->create();

        [$exit, $output] = $this->install();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Telegram-ға шығу: ЖОҚ', $output);
    }
}
