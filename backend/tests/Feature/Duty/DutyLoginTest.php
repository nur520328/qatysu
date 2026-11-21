<?php

namespace Tests\Feature\Duty;

use App\Enums\Shift;
use App\Models\DutySession;
use App\Models\User;
use Carbon\CarbonImmutable;

class DutyLoginTest extends DutyTestCase
{
    public function test_duty_enters_with_code_and_sees_only_own_shift_classes(): void
    {
        $this->classWith('5Ә', ['Сынақов Бірінші', 'Сынақов Екінші']);
        $this->classWith('5А', ['Сынақов Үшінші']);
        $this->classWith('11А', []);
        $this->classWith('6А', ['Бөтенов Төртінші'], Shift::Afternoon);
        $closed = $this->classWith('9А', ['Жабықов Бесінші']);
        $closed->update(['is_active' => false]);

        $response = $this->postJson('/api/duty/login', ['code' => $this->issue()])->assertOk();

        $response->assertJsonPath('date', '2026-11-20')->assertJsonPath('shift', 'morning')
            ->assertJsonPath('expires_at', '2026-11-20T13:10:00+05:00')->assertJsonPath('device_number', 1);
        $this->assertSame(['5А', '5Ә', '11А'], array_column($response->json('classes'), 'name'));
        $response->assertJsonPath('classes.1.student_count', 2)->assertJsonPath('classes.1.round', null);
    }

    public function test_second_tablet_with_the_same_code_gets_its_own_number(): void
    {
        $code = $this->issue();
        $this->dutyLogin($code);
        $this->dutyLogin($code);

        $this->getJson('/api/duty/session')->assertOk()->assertJsonPath('device_number', 2);
        $this->assertSame([1, 2], DutySession::query()->orderBy('id')->pluck('device_number')->all());
        $this->actingAs($this->admin)->getJson('/api/admin/duty-codes')->assertJsonPath('days.0.shifts.0.code.devices', 2);
    }

    public function test_wrong_code_is_rejected_and_attempts_are_limited_per_address(): void
    {
        $code = $this->issue();
        $wrong = $code === '000000' ? '000001' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/duty/login', ['code' => $wrong])->assertStatus(422)->assertJsonPath('code', 'invalid_duty_code');
        }
        // Шек таусылған соң дұрыс код та қабылданбайды.
        $this->postJson('/api/duty/login', ['code' => $code])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');

        $this->travel(301)->seconds();
        $this->postJson('/api/duty/login', ['code' => $code])->assertOk();
    }

    public function test_many_failures_across_addresses_pause_duty_login_and_warn_the_admin(): void
    {
        $code = $this->issue();
        $wrong = $code === '000000' ? '000001' : '000000';

        for ($i = 0; $i < 40; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.'.intdiv($i, 4).'.'.($i % 4 + 1)])
                ->postJson('/api/duty/login', ['code' => $wrong])->assertStatus(422);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.9.9'])
            ->postJson('/api/duty/login', ['code' => $code])->assertStatus(429);
        $this->actingAs($this->admin)->getJson('/api/admin/duty-codes')->assertJsonPath('login_blocked', true);
    }

    public function test_malformed_codes_are_rejected_without_lookup(): void
    {
        $this->issue();
        foreach (['', '12345', '1234567', 'abcdef', '12 456'] as $bad) {
            $this->postJson('/api/duty/login', ['code' => $bad])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_code_works_only_on_its_day_and_until_the_shift_ends(): void
    {
        $tomorrow = $this->issue(Shift::Morning, '2026-11-23');
        $this->postJson('/api/duty/login', ['code' => $tomorrow])->assertStatus(422);

        $today = $this->issue();
        $this->dutyLogin($today);
        $this->getJson('/api/duty/session')->assertOk();

        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 13, 10, 1));
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/duty/session')->assertUnauthorized()->assertJsonPath('code', 'duty_code_ended');
        $this->postJson('/api/duty/login', ['code' => $today])->assertStatus(422);

        $this->travelTo(CarbonImmutable::create(2026, 11, 23, 8, 5));
        $this->postJson('/api/duty/login', ['code' => $tomorrow])->assertOk();
    }

    public function test_logout_ends_the_tablet_session(): void
    {
        $this->dutyLogin($this->issue());
        $response = $this->postJson('/api/duty/logout')->assertNoContent();
        $this->adoptSessionCookie($response);

        $this->getJson('/api/duty/session')->assertUnauthorized()->assertJsonPath('code', 'duty_unauthenticated');
        $this->assertNotNull(DutySession::query()->sole()->ended_at);
    }

    public function test_duty_session_gives_no_access_to_staff_api_and_staff_cannot_open_duty_session(): void
    {
        $code = $this->issue();
        $this->dutyLogin($code);
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->getJson('/api/admin/classes')->assertUnauthorized();
        $this->getJson('/api/teacher/homeroom')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->signIn(User::factory()->create());
        $this->postJson('/api/duty/login', ['code' => $code])->assertStatus(409)->assertJsonPath('code', 'signed_in_as_user');
        $this->getJson('/api/duty/session')->assertUnauthorized();
    }
}
