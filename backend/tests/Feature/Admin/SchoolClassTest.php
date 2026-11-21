<?php

namespace Tests\Feature\Admin;

use App\Enums\Shift;
use App\Models\SchoolClass;
use App\Models\User;

class SchoolClassTest extends AdminTestCase
{
    public function test_creates_class_from_school_style_name(): void
    {
        $this->makeYear();
        $teacher = User::factory()->create(['full_name' => 'Сынақ Жетекші']);

        $this->postJson('/api/admin/classes', ['name' => '7В', 'shift' => 'afternoon', 'homeroom_user_id' => $teacher->id])
            ->assertCreated()
            ->assertJsonPath('class.name', '7В')
            ->assertJsonPath('class.grade', 7)
            ->assertJsonPath('class.letter', 'В')
            ->assertJsonPath('class.shift', 'afternoon')
            ->assertJsonPath('class.language', 'kk')
            ->assertJsonPath('class.homeroom.full_name', 'Сынақ Жетекші')
            ->assertJsonPath('class.student_count', 0);
    }

    public function test_rejects_names_the_school_would_not_write(): void
    {
        $this->makeYear();

        foreach (['7 В', '7в', '7', 'В7', '12А', '0А', '7-В', '7ВВ', '7"В"'] as $name) {
            $this->postJson('/api/admin/classes', ['name' => $name, 'shift' => 'morning'])
                ->assertStatus(422, "«{$name}» қабылданбауы тиіс");
        }
        $this->assertSame(0, SchoolClass::query()->count());
    }

    public function test_duplicate_name_and_missing_year_are_conflicts(): void
    {
        $this->postJson('/api/admin/classes', ['name' => '7В', 'shift' => 'afternoon'])
            ->assertStatus(409)->assertExactJson(['code' => 'no_current_year']);

        $year = $this->makeYear();
        $this->makeClass($year, '7В');
        $this->postJson('/api/admin/classes', ['name' => '7В', 'shift' => 'morning'])
            ->assertStatus(409)->assertExactJson(['code' => 'class_exists']);
    }

    public function test_list_is_ordered_by_grade_then_kazakh_alphabet_with_student_counts(): void
    {
        $year = $this->makeYear();
        foreach (['10А', '9Б', '9Ә', '9А', '5В', '9Ғ', '9Г'] as $name) {
            $this->makeClass($year, $name);
        }
        $this->enrol(SchoolClass::query()->where('grade', 9)->where('letter', 'Ә')->firstOrFail(), 'Абаев', 'Алмас');

        $response = $this->getJson('/api/admin/classes')->assertOk();

        $this->assertSame(['5В', '9А', '9Ә', '9Б', '9Г', '9Ғ', '10А'], array_column($response->json('classes'), 'name'));
        $this->assertSame(1, collect($response->json('classes'))->firstWhere('name', '9Ә')['student_count']);
        $this->assertSame(0, collect($response->json('classes'))->firstWhere('name', '9А')['student_count']);
    }

    public function test_homeroom_teacher_sees_own_open_classes_of_current_year_only(): void
    {
        $teacher = User::factory()->create();
        $old = $this->makeYear('2025–2026', '2025-09-01', '2026-05-25', current: false);
        $this->makeClass($old, '6Ә', Shift::Afternoon, $teacher);
        $year = $this->makeYear();
        $this->makeClass($year, '7Ә', Shift::Afternoon, $teacher);
        $this->makeClass($year, '10А', Shift::Morning, $teacher);
        $closed = $this->makeClass($year, '8Б', Shift::Afternoon, $teacher);
        $this->patchJson("/api/admin/classes/{$closed->id}", ['is_active' => false])->assertOk();

        $this->actingAs($teacher);
        $this->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.homeroom_classes', ['7Ә', '10А']);
    }

    public function test_update_changes_shift_homeroom_and_name_but_not_into_a_duplicate(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass($year, '7В');
        $this->makeClass($year, '7Г');
        $teacher = User::factory()->create();

        $this->patchJson("/api/admin/classes/{$class->id}", ['shift' => 'morning', 'homeroom_user_id' => $teacher->id, 'language' => 'ru'])
            ->assertOk()
            ->assertJsonPath('class.shift', 'morning')
            ->assertJsonPath('class.language', 'ru')
            ->assertJsonPath('class.homeroom.id', $teacher->id);
        $this->patchJson("/api/admin/classes/{$class->id}", ['homeroom_user_id' => null])
            ->assertOk()->assertJsonPath('class.homeroom', null);
        $this->patchJson("/api/admin/classes/{$class->id}", ['name' => '7Г'])
            ->assertStatus(409)->assertExactJson(['code' => 'class_exists']);
        $this->patchJson("/api/admin/classes/{$class->id}", ['name' => '7В'])->assertOk();
    }

    public function test_inactive_or_missing_user_cannot_be_homeroom(): void
    {
        $this->makeYear();
        $inactive = User::factory()->inactive()->create();

        $this->postJson('/api/admin/classes', ['name' => '7В', 'shift' => 'afternoon', 'homeroom_user_id' => $inactive->id])
            ->assertStatus(422)->assertExactJson(['code' => 'homeroom_invalid']);
        $this->postJson('/api/admin/classes', ['name' => '7В', 'shift' => 'afternoon', 'homeroom_user_id' => 999999])
            ->assertStatus(422)->assertExactJson(['code' => 'homeroom_invalid']);
    }

    public function test_teacher_cannot_manage_classes(): void
    {
        $year = $this->makeYear();
        $class = $this->makeClass($year, '7В');
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/classes')->assertStatus(403);
        $this->postJson('/api/admin/classes', ['name' => '7Г', 'shift' => 'afternoon'])->assertStatus(403);
        $this->patchJson("/api/admin/classes/{$class->id}", ['shift' => 'morning'])->assertStatus(403);
    }
}
