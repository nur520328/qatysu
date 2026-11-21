<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_with_working_one_time_password(): void
    {
        $exit = Artisan::call('qatysu:create-admin', ['login' => ' Zavuch ', 'full_name' => 'Сынақ Завуч']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $user = User::query()->where('login', 'zavuch')->firstOrFail();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue($user->must_change_password);

        $this->assertSame(1, preg_match('/Бір реттік пароль: (\S+)/u', $output, $m));
        $this->assertTrue(PasswordPolicy::isStrong($m[1]));

        $this->postJson('/api/auth/login', ['login' => 'zavuch', 'password' => $m[1]])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true);
    }

    public function test_refuses_taken_login(): void
    {
        User::factory()->create(['login' => 'zavuch']);

        $exit = Artisan::call('qatysu:create-admin', ['login' => 'ZAVUCH', 'full_name' => 'Екінші']);

        $this->assertSame(1, $exit);
        $this->assertSame(1, User::query()->count());
    }
}
