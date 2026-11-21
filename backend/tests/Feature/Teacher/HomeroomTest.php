<?php

namespace Tests\Feature\Teacher;

use App\Models\User;
use App\Services\EnrollmentService;
use Carbon\CarbonImmutable;
use Tests\Feature\Admin\AdminTestCase;

/** Жетекші өз сыныбын көреді, бірақ ештеңені өзгерте алмайды. Барлық дерек жасанды. */
class HomeroomTest extends AdminTestCase
{
    public function test_homeroom_teacher_sees_only_own_open_classes_with_todays_roster(): void
    {
        $year = $this->makeYear();
        $old = $this->makeYear('2025–2026', '2025-09-01', '2026-05-25', current: false);
        $teacher = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->makeClass($year, '7Ә', homeroom: $teacher);
        $second = $this->makeClass($year, '5А', homeroom: $teacher);
        $foreign = $this->makeClass($year, '7В', homeroom: $other);
        $closed = $this->makeClass($year, '8А', homeroom: $teacher);
        $closed->update(['is_active' => false]);
        $this->makeClass($old, '6Ә', homeroom: $teacher);

        $this->enrol($mine, 'Сынақов', 'Бірінші');
        $this->enrol($mine, 'Әлібеков', 'Екінші');
        $moved = $this->enrol($mine, 'Көшкенов', 'Үшінші');
        app(EnrollmentService::class)->transfer($moved, $foreign, CarbonImmutable::parse('2026-11-10'));
        $this->enrol($foreign, 'Бөтенов', 'Төртінші');

        $response = $this->actingAs($teacher)->getJson('/api/teacher/homeroom')->assertOk();

        $response->assertJsonPath('date', '2026-11-20');
        $this->assertSame(['5А', '7Ә'], array_column(array_column($response->json('classes'), 'class'), 'name'));
        $this->assertSame([], $response->json('classes.0.students'));
        $this->assertSame(['Әлібеков', 'Сынақов'], array_column($response->json('classes.1.students'), 'last_name'));
        $response->assertJsonPath('classes.1.class.student_count', 2);
    }

    public function test_teacher_without_homeroom_gets_empty_list(): void
    {
        $year = $this->makeYear();
        $this->enrol($this->makeClass($year, '7Ә', homeroom: User::factory()->create()), 'Сынақов', 'Бірінші');

        $this->actingAs(User::factory()->create())->getJson('/api/teacher/homeroom')
            ->assertOk()->assertExactJson(['date' => '2026-11-20', 'classes' => []]);
    }

    public function test_homeroom_teacher_cannot_change_classes_or_students(): void
    {
        $year = $this->makeYear();
        $teacher = User::factory()->create();
        $class = $this->makeClass($year, '7Ә', homeroom: $teacher);
        $student = $this->enrol($class, 'Сынақов', 'Бірінші');
        $this->actingAs($teacher);

        $this->patchJson("/api/admin/classes/{$class->id}", ['is_active' => false])->assertForbidden();
        $this->getJson("/api/admin/classes/{$class->id}/students")->assertForbidden();
        $this->postJson("/api/admin/classes/{$class->id}/students", ['last_name' => 'Жаңа', 'first_name' => 'Оқушы'])->assertForbidden();
        $this->patchJson("/api/admin/students/{$student->id}", ['last_name' => 'Өзгерген'])->assertForbidden();
        $this->postJson("/api/admin/students/{$student->id}/withdraw")->assertForbidden();
        $this->postJson("/api/admin/students/{$student->id}/transfer", ['class_id' => $class->id])->assertForbidden();

        $this->assertTrue($class->refresh()->is_active);
        $this->assertSame('Сынақов', $student->refresh()->last_name);
        $this->assertDatabaseCount('students', 1);
    }

    public function test_guest_and_one_time_password_holder_are_rejected(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/teacher/homeroom')->assertUnauthorized();

        $this->actingAs(User::factory()->mustChangePassword()->create())
            ->getJson('/api/teacher/homeroom')->assertForbidden();
    }
}
