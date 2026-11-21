<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Рөл бойынша қолжетімділік серверде шешілетінін тексереді.
 * Нақты жұмыс маршруттары 3-кезеңде пайда болады, сондықтан мұнда
 * дәл сол middleware тізбегімен сынақ маршруттары тіркеледі.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum', 'account', 'role:admin'])
            ->get('/api/_test/admin', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'auth:sanctum', 'account', 'role:admin,teacher'])
            ->get('/api/_test/staff', fn () => response()->json(['ok' => true]));
    }

    public function test_guest_is_rejected_everywhere(): void
    {
        $this->getJson('/api/_test/admin')->assertStatus(401)->assertExactJson(['code' => 'unauthenticated']);
        $this->getJson('/api/_test/staff')->assertStatus(401);
    }

    public function test_teacher_cannot_open_admin_route(): void
    {
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/_test/admin')->assertStatus(403)->assertExactJson(['code' => 'forbidden']);
        $this->getJson('/api/_test/staff')->assertOk();
    }

    public function test_admin_can_open_both(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->getJson('/api/_test/admin')->assertOk();
        $this->getJson('/api/_test/staff')->assertOk();
    }

    public function test_one_time_password_blocks_work_routes_but_not_auth_routes(): void
    {
        $this->actingAs(User::factory()->admin()->mustChangePassword()->create());

        $this->getJson('/api/_test/admin')
            ->assertStatus(403)
            ->assertExactJson(['code' => 'password_change_required']);
        $this->getJson('/api/auth/me')->assertOk();
    }

    public function test_deactivated_account_is_rejected_on_work_routes(): void
    {
        $this->actingAs(User::factory()->admin()->inactive()->create());

        $this->getJson('/api/_test/admin')->assertStatus(401);
    }

    public function test_unknown_api_path_returns_code_not_html(): void
    {
        $this->getJson('/api/zhoq-zhol')->assertStatus(404)->assertExactJson(['code' => 'not_found']);
    }
}
