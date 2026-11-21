<?php

namespace Tests\Feature\Duty;

use App\Enums\Shift;
use App\Models\DutyCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\Duty\DutyCodeService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

class DutyCodeTest extends DutyTestCase
{
    public function test_admin_sees_today_and_next_school_day_with_both_shifts(): void
    {
        $this->classWith('5А', ['Сынақов Бірінші']);
        $this->classWith('6А', ['Сынақов Екінші'], Shift::Afternoon);
        $this->classWith('6Ә', [], Shift::Afternoon);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/duty-codes')->assertOk();

        // Жұмадан кейінгі оқу күні — дүйсенбі.
        $this->assertSame(['2026-11-20', '2026-11-23'], array_column($response->json('days'), 'date'));
        $response->assertJsonPath('days.0.is_today', true)
            ->assertJsonPath('days.0.shifts.0.shift', 'morning')
            ->assertJsonPath('days.0.shifts.0.classes_total', 1)
            ->assertJsonPath('days.0.shifts.0.code', null)
            ->assertJsonPath('days.0.shifts.1.classes_total', 2)
            ->assertJsonPath('days.0.shifts.1.ends_at', '2026-11-20T18:40:00+05:00')
            ->assertJsonPath('login_blocked', false);
    }

    public function test_issued_code_is_six_digits_stored_encrypted_and_visible_to_admin_until_the_shift_ends(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/duty-codes', ['date' => '2026-11-20', 'shift' => 'morning'])->assertCreated();

        $plain = $response->json('code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $plain);
        $response->assertJsonPath('shift.code.issued_by', $this->admin->full_name)->assertJsonPath('shift.code.devices', 0);

        $stored = DutyCode::query()->sole();
        $this->assertTrue(Hash::check($plain, $stored->code_hash));
        $this->assertSame('2026-11-20 13:10:00', $stored->expires_at->format('Y-m-d H:i:s'));
        // Дерекқорда код ашық мәтінмен жатпайды: шифрланған.
        $this->assertStringNotContainsString($plain, json_encode($stored->getAttributes()));
        $this->assertSame($plain, $stored->code_encrypted);

        // Завуч оны күні бойы қайта көре алады…
        $this->getJson('/api/admin/duty-codes')->assertJsonPath('days.0.shifts.0.code.code', $plain);
        // …ал смена біткен соң көрсетілмейді, түнгі тазалауда шифрланған көшірмесі де өшеді.
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 13, 10, 1));
        $this->getJson('/api/admin/duty-codes')->assertJsonPath('days.0.shifts.0.code.code', null);
        $this->assertSame(1, app(DutyCodeService::class)->forgetExpired());
        $this->assertNull($stored->refresh()->code_encrypted);
    }

    public function test_new_code_replaces_the_old_one_and_signs_tablets_out(): void
    {
        $this->classWith('5А', ['Сынақов Бірінші']);
        $old = $this->issue();
        $this->dutyLogin($old);
        $this->getJson('/api/duty/session')->assertOk();
        $tablet = $this->defaultCookies;

        $new = $this->actingAs($this->admin)->postJson('/api/admin/duty-codes', ['date' => '2026-11-20', 'shift' => 'morning'])->json('code');
        $this->assertNotSame($old, $new);
        $this->assertSame(1, DutyCode::query()->whereNull('revoked_at')->count());

        $this->app['auth']->forgetGuards();
        $this->defaultCookies = $tablet;
        $this->getJson('/api/duty/session')->assertUnauthorized()->assertJsonPath('code', 'duty_code_ended');
        $this->postJson('/api/duty/login', ['code' => $old])->assertStatus(422);
    }

    public function test_admin_can_revoke_a_code(): void
    {
        $this->issue();
        $code = DutyCode::query()->sole();

        $this->actingAs($this->admin)->deleteJson("/api/admin/duty-codes/{$code->id}")->assertOk()->assertJsonPath('shift.code', null);
        $this->assertNotNull($code->refresh()->revoked_at);
        $this->assertNull($code->code_encrypted);
    }

    public function test_code_is_issued_only_for_today_or_the_next_school_day_and_not_after_the_shift(): void
    {
        $this->actingAs($this->admin);
        $issue = fn (string $date, string $shift = 'morning') => $this->postJson('/api/admin/duty-codes', ['date' => $date, 'shift' => $shift]);

        $issue('2026-11-23')->assertCreated();                                             // келесі оқу күні
        $issue('2026-11-21')->assertStatus(422)->assertJsonPath('code', 'date_not_issuable'); // сенбі
        $issue('2026-11-24')->assertStatus(422)->assertJsonPath('code', 'date_not_issuable'); // тым алыс
        $issue('2026-11-19')->assertStatus(422)->assertJsonPath('code', 'date_not_issuable'); // өткен күн

        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 13, 11));
        $issue('2026-11-20')->assertStatus(422)->assertJsonPath('code', 'shift_ended');
        $issue('2026-11-20', 'afternoon')->assertCreated();
    }

    public function test_holiday_is_skipped_and_shift_end_follows_the_setting(): void
    {
        app(CalendarService::class)->setRange($this->year, CarbonImmutable::parse('2026-11-23'), CarbonImmutable::parse('2026-11-24'), false, 'каникул');
        Setting::query()->create(['key' => 'morning_shift_end', 'value' => '780']);

        $response = $this->actingAs($this->admin)->getJson('/api/admin/duty-codes')->assertOk();

        $this->assertSame(['2026-11-20', '2026-11-25'], array_column($response->json('days'), 'date'));
        $response->assertJsonPath('days.0.shifts.0.ends_at', '2026-11-20T13:00:00+05:00');
    }

    public function test_only_admin_manages_codes(): void
    {
        $this->issue();
        $id = DutyCode::query()->value('id');

        $this->actingAs(User::factory()->create());
        $this->getJson('/api/admin/duty-codes')->assertForbidden();
        $this->postJson('/api/admin/duty-codes', ['date' => '2026-11-20', 'shift' => 'morning'])->assertForbidden();
        $this->deleteJson("/api/admin/duty-codes/{$id}")->assertForbidden();
    }
}
