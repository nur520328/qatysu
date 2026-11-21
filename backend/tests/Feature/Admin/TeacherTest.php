<?php

namespace Tests\Feature\Admin;

use App\Enums\Shift;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\PasswordPolicy;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TeacherTest extends AdminTestCase
{
    private const HEADER = ['№', 'Тегі', 'Аты', 'Әкесінің аты (міндетті емес)', 'Жетекші сыныбы (міндетті емес)', 'Логин (міндетті емес)'];

    public function test_import_creates_accounts_with_one_time_passwords_shown_once(): void
    {
        $year = $this->makeYear();
        $this->makeClass($year, '7В');
        $file = fn () => $this->xlsx(['Мұғалімдер' => [
            self::HEADER,
            ['үлгі', 'Ахметова', 'Гүлнар', 'Серікқызы', '7В', null],
            [1, 'Сәрсенов', 'Болат', 'Қайратұлы', '7В', null],
            [2, 'Жұмағұлова', 'Айгүл', null, null, null],
            [3, 'Қалиева', 'Сәуле', null, null, 'kalieva'],
        ], 'Нұсқау' => [['Мұғалімдер тізімінің үлгісі']]]);

        $preview = $this->post('/api/admin/import/teachers', ['file' => $file()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary', ['new' => 3, 'exists' => 0, 'errors' => 0, 'warnings' => 0])
            ->assertJsonPath('credentials', null)
            ->assertJsonPath('new.0.login', 'sarsenov.b')
            ->assertJsonPath('new.0.homeroom', '7В')
            ->assertJsonPath('new.1.login', 'zhumagulova.a')
            ->assertJsonPath('new.2.login', 'kalieva');
        $this->assertSame(1, User::query()->count()); // тек әкімші

        $commit = $this->post('/api/admin/import/teachers', ['file' => $file(), 'digest' => $preview->json('digest')], ['Accept' => 'application/json'])
            ->assertOk();
        $credentials = $commit->json('credentials');

        $this->assertCount(3, $credentials);
        $this->assertSame(['full_name' => 'Сәрсенов Болат Қайратұлы', 'login' => 'sarsenov.b', 'homeroom' => '7В'],
            array_diff_key($credentials[0], ['password' => true]));
        foreach ($credentials as $row) {
            $this->assertTrue(PasswordPolicy::isStrong($row['password']));
            $user = User::query()->where('login', $row['login'])->firstOrFail();
            $this->assertTrue(Hash::check($row['password'], $user->password));
            $this->assertTrue($user->must_change_password);
            // Дерекқорда пароль тек хеш түрінде.
            $this->assertStringNotContainsString($row['password'], json_encode($user->getAttributes()));
        }
        $this->assertSame('sarsenov.b', SchoolClass::query()->firstOrFail()->homeroom->login);

        // Жаңа мұғалім бір реттік парольмен кіре алады.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => 'sarsenov.b', 'password' => $credentials[0]['password']])
            ->assertOk()->assertJsonPath('user.homeroom_classes', ['7В'])->assertJsonPath('user.must_change_password', true);
    }

    public function test_reimport_skips_existing_and_same_surname_gets_numbered_login(): void
    {
        $this->makeYear();
        User::factory()->create(['full_name' => 'Сәрсенов Болат Қайратұлы', 'login' => 'sarsenov.b']);
        $file = $this->xlsx(['Мұғалімдер' => [
            self::HEADER,
            [1, 'Сәрсенов', 'Болат', 'Қайратұлы', null, null],
            [2, 'Сарсенов', 'Бекзат', null, null, null],
            [3, 'Сәрсенов', 'Бауыржан', null, null, null],
        ]]);

        $this->post('/api/admin/import/teachers', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary', ['new' => 2, 'exists' => 1, 'errors' => 0, 'warnings' => 0])
            ->assertJsonPath('new.0.login', 'sarsenov.b2')
            ->assertJsonPath('new.1.login', 'sarsenov.b3');
    }

    public function test_import_reports_bad_rows_and_never_replaces_an_existing_homeroom(): void
    {
        $year = $this->makeYear();
        $this->makeClass($year, '7В', Shift::Afternoon, User::factory()->create(['full_name' => 'Бұрынғы Жетекші']));
        $this->makeClass($year, '7Г');
        User::factory()->create(['login' => 'bos.emes']);
        $file = $this->xlsx(['Мұғалімдер' => [
            self::HEADER,
            [1, null, 'Аты ғана', null, null, null],
            [2, 'Қате', 'Логин', null, null, 'Қате Логин!'],
            [3, 'Бос', 'Емес', null, null, 'bos.emes'],
            [4, 'Жоқ', 'Сынып', null, '9Ж', null],
            [5, 'Қате', 'Сынып', null, '7 г', null],
            [6, 'Бірінші', 'Жетекші', null, '7Г', null],
            [7, 'Екінші', 'Жетекші', null, '7Г', null],
            [8, 'Жаңа', 'Мұғалім', null, '7В', null],
        ]]);

        $response = $this->post('/api/admin/import/teachers', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('summary', ['new' => 2, 'exists' => 0, 'errors' => 6, 'warnings' => 1]);

        $this->assertSame([
            2 => 'missing_last_name', 3 => 'invalid_login', 4 => 'login_taken',
            5 => 'class_not_found', 6 => 'invalid_class', 8 => 'duplicate_homeroom_in_file',
        ], collect($response->json('problems'))->pluck('code', 'row')->all());
        $late = collect($response->json('new'))->firstWhere('row', 9);
        $this->assertSame(['homeroom' => null, 'warning' => 'homeroom_taken'], ['homeroom' => $late['homeroom'], 'warning' => $late['warning']]);
    }

    public function test_file_without_teacher_header_is_refused(): void
    {
        $file = $this->xlsx(['Бет' => [['Бірдеңе'], ['тағы']]]);

        $this->post('/api/admin/import/teachers', ['file' => $file], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertExactJson(['code' => 'header_not_found']);
    }

    public function test_single_account_can_be_added_listed_renamed_and_deactivated(): void
    {
        $created = $this->postJson('/api/admin/users', ['last_name' => 'Оспанов', 'first_name' => 'Ерік'])
            ->assertCreated()
            ->assertJsonPath('user.login', 'ospanov.e')
            ->assertJsonPath('user.role', 'teacher')
            ->assertJsonPath('user.must_change_password', true);
        $id = $created->json('user.id');
        $this->assertTrue(PasswordPolicy::isStrong($created->json('one_time_password')));

        $this->postJson('/api/admin/users', ['last_name' => 'Оспанов', 'first_name' => 'Ерік', 'login' => 'ospanov.e'])
            ->assertStatus(409)->assertExactJson(['code' => 'login_taken']);
        $this->postJson('/api/admin/users', ['last_name' => 'Оспанов', 'first_name' => 'Ерік', 'login' => 'Қате логин'])
            ->assertStatus(422)->assertExactJson(['code' => 'invalid_login']);

        $list = $this->getJson('/api/admin/users')->assertOk()->json('users');
        $this->assertContains('Оспанов Ерік', array_column($list, 'full_name'));
        $this->assertArrayNotHasKey('password', $list[0]);

        DB::table('sessions')->insert(['id' => 'ashyq', 'user_id' => $id, 'payload' => '', 'last_activity' => time()]);
        $this->patchJson("/api/admin/users/{$id}", ['full_name' => ' Оспанов  Ерік Маратұлы ', 'is_active' => false])
            ->assertOk()->assertJsonPath('user.full_name', 'Оспанов Ерік Маратұлы')->assertJsonPath('user.is_active', false);
        $this->assertDatabaseMissing('sessions', ['id' => 'ashyq']);
    }

    public function test_admin_resets_password_and_old_one_stops_working(): void
    {
        $teacher = User::factory()->create(['login' => 'mugalim']);
        DB::table('sessions')->insert(['id' => 'ashyq', 'user_id' => $teacher->id, 'payload' => '', 'last_activity' => time()]);

        $new = $this->postJson("/api/admin/users/{$teacher->id}/reset-password")
            ->assertOk()->assertJsonPath('user.must_change_password', true)->json('one_time_password');

        $this->assertDatabaseMissing('sessions', ['id' => 'ashyq']);
        $this->assertTrue(Hash::check($new, $teacher->refresh()->password));
        $this->assertFalse(Hash::check(UserFactory::DEFAULT_PASSWORD, $teacher->password));
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $this->patchJson("/api/admin/users/{$this->admin->id}", ['is_active' => false])
            ->assertStatus(422)->assertExactJson(['code' => 'cannot_change_self']);
        $this->patchJson("/api/admin/users/{$this->admin->id}", ['role' => 'teacher'])
            ->assertStatus(422)->assertExactJson(['code' => 'cannot_change_self']);
        $this->patchJson("/api/admin/users/{$this->admin->id}", ['full_name' => 'Сынақ Завуч'])->assertOk();
        $this->assertTrue($this->admin->refresh()->is_active);
    }

    public function test_teacher_cannot_manage_accounts(): void
    {
        $other = User::factory()->create();
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/users')->assertStatus(403);
        $this->postJson('/api/admin/users', ['last_name' => 'А', 'first_name' => 'Б'])->assertStatus(403);
        $this->postJson("/api/admin/users/{$other->id}/reset-password")->assertStatus(403);
        $this->patchJson("/api/admin/users/{$other->id}", ['role' => 'admin'])->assertStatus(403);
    }
}
