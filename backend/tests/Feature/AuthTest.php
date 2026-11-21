<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** docs/api.md, «Кіру» бөлімі. */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = UserFactory::DEFAULT_PASSWORD;

    public function test_guest_gets_unauthenticated_code(): void
    {
        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertExactJson(['code' => 'unauthenticated']);
    }

    public function test_login_returns_user_object_and_opens_session(): void
    {
        $user = User::factory()->create(['login' => 'mugalim7', 'full_name' => 'Сынақ Мұғалім']);

        $response = $this->postJson('/api/auth/login', ['login' => 'mugalim7', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertExactJson(['user' => [
                'id' => $user->id,
                'full_name' => 'Сынақ Мұғалім',
                'role' => 'teacher',
                'locale' => 'kk',
                'must_change_password' => false,
                'homeroom_classes' => [],
            ]]);

        // Guard жадын тазалап, тек cookie арқылы тексереміз: сеанс шынымен ашылған.
        $this->adoptSessionCookie($response);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_login_ignores_case_and_surrounding_spaces(): void
    {
        User::factory()->create(['login' => 'mugalim7']);

        $this->postJson('/api/auth/login', ['login' => '  MuGalim7 ', 'password' => self::PASSWORD])
            ->assertOk();
    }

    public function test_wrong_password_unknown_login_and_inactive_account_look_the_same(): void
    {
        User::factory()->create(['login' => 'bar']);
        User::factory()->inactive()->create(['login' => 'oshirilgen']);

        $attempts = [
            ['login' => 'bar', 'password' => 'qate-parol-1'],
            ['login' => 'zhoq', 'password' => self::PASSWORD],
            ['login' => 'oshirilgen', 'password' => self::PASSWORD],
        ];

        foreach ($attempts as $attempt) {
            $this->postJson('/api/auth/login', $attempt)
                ->assertStatus(422)
                ->assertExactJson(['code' => 'invalid_credentials']);
        }

        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_sixth_failed_attempt_is_locked_out_even_with_correct_password(): void
    {
        User::factory()->create(['login' => 'bar']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['login' => 'bar', 'password' => 'qate-parol-1'])
                ->assertStatus(422);
        }

        $this->postJson('/api/auth/login', ['login' => 'bar', 'password' => self::PASSWORD])
            ->assertStatus(429)
            ->assertExactJson(['code' => 'too_many_attempts']);
    }

    public function test_lockout_is_per_login_and_cleared_by_success(): void
    {
        User::factory()->create(['login' => 'bar']);
        User::factory()->create(['login' => 'basqa']);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', ['login' => 'bar', 'password' => 'qate-parol-1']);
        }
        // Сәтті кіру есептегішті тазалайды.
        $this->postJson('/api/auth/login', ['login' => 'bar', 'password' => self::PASSWORD])->assertOk();
        $this->postJson('/api/auth/logout')->assertNoContent();

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/login', ['login' => 'bar', 'password' => 'qate-parol-1'])
                ->assertStatus(422);
        }
        // Басқа логин бұғатталмаған.
        $this->postJson('/api/auth/login', ['login' => 'basqa', 'password' => self::PASSWORD])->assertOk();
    }

    public function test_missing_fields_return_validation_code_without_text(): void
    {
        $this->postJson('/api/auth/login', ['login' => 'bar'])
            ->assertStatus(422)
            ->assertExactJson(['code' => 'validation_failed', 'fields' => ['password']]);
    }

    public function test_logout_closes_session(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $this->getJson('/api/auth/me')->assertOk();
        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);

        $response = $this->postJson('/api/auth/logout')->assertNoContent();

        // Серверде осы пайдаланушыға байланған сеанс қалмайды.
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        // Жаңа cookie-мен де, ескісімен де кіру жабық.
        $this->adoptSessionCookie($response);
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_deactivated_account_loses_its_open_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->getJson('/api/auth/me')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->getJson('/api/auth/me')
            ->assertStatus(401)
            ->assertExactJson(['code' => 'unauthenticated']);
    }

    public function test_one_time_password_must_be_changed_and_then_flag_clears(): void
    {
        $user = User::factory()->mustChangePassword()->create();
        $this->actingAs($user);

        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.must_change_password', true);

        $this->postJson('/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'zhana-parol-9',
        ])->assertOk()->assertJsonPath('user.must_change_password', false);

        $user->refresh();
        $this->assertTrue(Hash::check('zhana-parol-9', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_password_change_rejections(): void
    {
        $this->actingAs(User::factory()->create());

        $cases = [
            'wrong_current_password' => ['current_password' => 'qate-parol-1', 'new_password' => 'zhana-parol-9'],
            'weak_password' => ['current_password' => self::PASSWORD, 'new_password' => 'qysqa1'],
            'same_password' => ['current_password' => self::PASSWORD, 'new_password' => self::PASSWORD],
        ];

        foreach ($cases as $code => $payload) {
            $this->postJson('/api/auth/password', $payload)
                ->assertStatus(422)
                ->assertExactJson(['code' => $code]);
        }
    }

    public function test_password_change_closes_sessions_on_other_devices(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            'id' => 'basqa-qurylgy',
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->signIn($user);
        $response = $this->postJson('/api/auth/password', [
            'current_password' => self::PASSWORD,
            'new_password' => 'zhana-parol-9',
        ])->assertOk();

        // Басқа құрылғының сеансы жабылды, ал осы құрылғы кірген күйінде қалды.
        $this->assertDatabaseMissing('sessions', ['id' => 'basqa-qurylgy']);
        $this->adoptSessionCookie($response);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.id', $user->id);
    }

    public function test_locale_is_saved_and_unknown_locale_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->postJson('/api/auth/locale', ['locale' => 'zh'])
            ->assertOk()
            ->assertJsonPath('user.locale', 'zh');
        $this->assertSame('zh', $user->refresh()->locale->value);

        $this->postJson('/api/auth/locale', ['locale' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_response_never_exposes_password_or_telegram_id(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['telegram_chat_id' => '123456'])->save();
        $this->actingAs($user);

        $body = $this->getJson('/api/auth/me')->assertOk()->getContent();

        $this->assertStringNotContainsString('password":', str_replace('must_change_password', '', $body));
        $this->assertStringNotContainsString('123456', $body);
    }
}
