<?php

namespace Tests\Feature\Admin;

use App\Models\Setting;
use App\Models\User;

class SettingTest extends AdminTestCase
{
    public function test_defaults_match_the_agreed_design(): void
    {
        $this->getJson('/api/admin/settings')->assertOk()->assertExactJson(['settings' => [
            'max_lessons_per_shift' => 7,
            'homeroom_edit_days' => 14,
            'pending_reason_days' => 3,
            'morning_shift_end' => 790,
            'afternoon_shift_end' => 1120,
        ]]);
    }

    public function test_update_persists_and_enforces_limits(): void
    {
        $this->patchJson('/api/admin/settings', ['max_lessons_per_shift' => 8])
            ->assertOk()->assertJsonPath('settings.max_lessons_per_shift', 8)
            ->assertJsonPath('settings.homeroom_edit_days', 14);
        $this->assertSame(8, Setting::int('max_lessons_per_shift'));

        $this->patchJson('/api/admin/settings', ['max_lessons_per_shift' => 0])->assertStatus(422);
        $this->patchJson('/api/admin/settings', ['homeroom_edit_days' => 365])->assertStatus(422);
        $this->assertSame(8, Setting::int('max_lessons_per_shift'));
    }

    public function test_teacher_cannot_read_or_change_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/settings')->assertStatus(403);
        $this->patchJson('/api/admin/settings', ['max_lessons_per_shift' => 8])->assertStatus(403);
    }
}
