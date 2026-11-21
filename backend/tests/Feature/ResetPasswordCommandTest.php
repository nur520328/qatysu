<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OneTimePassword;
use App\Support\PasswordPolicy;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_issues_new_one_time_password_and_closes_sessions(): void
    {
        $user = User::factory()->admin()->create(['login' => 'zavuch']);
        DB::table('sessions')->insert(['id' => 'ashyq', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

        $exit = Artisan::call('qatysu:reset-password', ['login' => ' ZAVUCH ']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        // Пароль бірінші жолда: панель шығыстың басын ғана көрсетеді.
        $this->assertStringStartsWith('Бір реттік пароль: ', $output);
        $this->assertSame(1, preg_match('/^Бір реттік пароль: (\S+)$/mu', $output, $m), $output);
        $this->assertDatabaseMissing('sessions', ['id' => 'ashyq']);
        $this->assertTrue($user->refresh()->must_change_password);

        $this->postJson('/api/auth/login', ['login' => 'zavuch', 'password' => UserFactory::DEFAULT_PASSWORD])
            ->assertStatus(422);
        $this->postJson('/api/auth/login', ['login' => 'zavuch', 'password' => $m[1]])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_unknown_login_changes_nothing(): void
    {
        $exit = Artisan::call('qatysu:reset-password', ['login' => 'zhoq']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('табылмады', Artisan::output());
    }

    public function test_one_time_passwords_are_readable_strong_and_different(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $password = OneTimePassword::generate();
            $this->assertMatchesRegularExpression('/\A[a-hj-km-np-z2-9]{4}-[a-hj-km-np-z2-9]{4}-[a-hj-km-np-z2-9]{4}\z/', $password);
            $this->assertTrue(PasswordPolicy::isStrong($password));
            $seen[$password] = true;
        }
        $this->assertCount(200, $seen);
    }
}
