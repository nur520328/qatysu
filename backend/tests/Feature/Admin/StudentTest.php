<?php

namespace Tests\Feature\Admin;

use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;

class StudentTest extends AdminTestCase
{
    public function test_adds_student_with_cleaned_names_and_only_name_fields(): void
    {
        $class = $this->makeClass($this->makeYear(), '7В');

        $this->postJson("/api/admin/classes/{$class->id}/students", [
            'last_name' => '  Тоқтаров ', 'first_name' => "Әділ\t", 'patronymic' => '  ',
        ])->assertCreated()->assertJsonPath('student.last_name', 'Тоқтаров')
            ->assertJsonPath('student.first_name', 'Әділ')
            ->assertJsonPath('student.patronymic', null)
            ->assertJsonPath('student.class_id', $class->id);

        $student = Student::query()->firstOrFail();
        // Дербес деректі азайту: кестеде аты-жөнінен басқа жеке дерек бағаны жоқ.
        $this->assertEqualsCanonicalizing(
            ['id', 'last_name', 'first_name', 'patronymic', 'is_active', 'created_at', 'updated_at'],
            array_keys($student->getAttributes()),
        );
        $this->assertSame('2026-11-20', StudentEnrollment::query()->firstOrFail()->start_date->toDateString());
    }

    public function test_roster_follows_kazakh_alphabet_like_the_class_journal(): void
    {
        $class = $this->makeClass($this->makeYear(), '7В');
        foreach ([['Қасымов', 'Ерлан'], ['Кәрімов', 'Асхат'], ['Әбенов', 'Арман'], ['Абаев', 'Алмас'],
            ['Ғалымов', 'Санжар'], ['Галиев', 'Тимур'], ['Өтегенов', 'Бауыржан'], ['Оспанов', 'Ерік'],
            ['Абаев', 'Әлихан'], ['Ысқақов', 'Рамазан'], ['Іңкәрбек', 'Дана'], ['Ұлықбек', 'Нұрлан'], ['Усенов', 'Диас']] as [$last, $first]) {
            $this->enrol($class, $last, $first);
        }

        $names = array_map(
            static fn (array $s): string => $s['last_name'].' '.$s['first_name'],
            $this->getJson("/api/admin/classes/{$class->id}/students")->assertOk()->json('students'),
        );

        $this->assertSame([
            'Абаев Алмас', 'Абаев Әлихан', 'Әбенов Арман', 'Галиев Тимур', 'Ғалымов Санжар',
            'Кәрімов Асхат', 'Қасымов Ерлан', 'Оспанов Ерік', 'Өтегенов Бауыржан',
            'Усенов Диас', 'Ұлықбек Нұрлан', 'Ысқақов Рамазан', 'Іңкәрбек Дана',
        ], $names);
    }

    public function test_same_person_cannot_be_added_to_a_class_twice(): void
    {
        $class = $this->makeClass($this->makeYear(), '7В');
        $this->enrol($class, 'Тоқтаров', 'Әділ', 'Маратұлы');

        $this->postJson("/api/admin/classes/{$class->id}/students", ['last_name' => 'тоқтаров', 'first_name' => 'ӘДІЛ', 'patronymic' => 'Маратұлы'])
            ->assertStatus(409)->assertExactJson(['code' => 'student_exists']);
        // Әкесінің аты басқа болса, бұл басқа оқушы.
        $this->postJson("/api/admin/classes/{$class->id}/students", ['last_name' => 'Тоқтаров', 'first_name' => 'Әділ', 'patronymic' => 'Серікұлы'])
            ->assertCreated();
    }

    public function test_transfer_keeps_past_days_in_the_old_class(): void
    {
        $year = $this->makeYear();
        $old = $this->makeClass($year, '7В');
        $new = $this->makeClass($year, '7Г');
        $student = $this->enrol($old, 'Тоқтаров', 'Әділ');

        $this->postJson("/api/admin/students/{$student->id}/transfer", ['class_id' => $new->id, 'date' => '2026-11-10'])
            ->assertOk()->assertJsonPath('student.class_id', $new->id);

        $roster = fn (int $classId, string $date): array => array_column(
            $this->getJson("/api/admin/classes/{$classId}/students?date={$date}")->assertOk()->json('students'), 'id');

        $this->assertSame([$student->id], $roster($old->id, '2026-11-09'));
        $this->assertSame([], $roster($new->id, '2026-11-09'));
        $this->assertSame([], $roster($old->id, '2026-11-10'));
        $this->assertSame([$student->id], $roster($new->id, '2026-11-10'));
        $this->assertSame([$student->id], $roster($new->id, '2026-11-20'));
        $this->assertSame(1, StudentEnrollment::query()->where('student_id', $student->id)->whereNull('end_date')->count());
    }

    public function test_transfer_on_the_enrollment_day_just_corrects_the_class(): void
    {
        $year = $this->makeYear();
        $old = $this->makeClass($year, '7В');
        $new = $this->makeClass($year, '7Г');
        $student = $this->enrol($old, 'Тоқтаров', 'Әділ', date: '2026-11-20');

        $this->postJson("/api/admin/students/{$student->id}/transfer", ['class_id' => $new->id])->assertOk();

        $this->assertSame(1, StudentEnrollment::query()->where('student_id', $student->id)->count());
        $this->assertSame($new->id, StudentEnrollment::query()->firstOrFail()->class_id);
    }

    public function test_transfer_rejections(): void
    {
        $year = $this->makeYear();
        $old = $this->makeClass($year, '7В');
        $new = $this->makeClass($year, '7Г');
        $closed = $this->makeClass($year, '7Д');
        $closed->forceFill(['is_active' => false])->save();
        $student = $this->enrol($old, 'Тоқтаров', 'Әділ', date: '2026-10-01');
        $url = "/api/admin/students/{$student->id}/transfer";

        $this->postJson($url, ['class_id' => $old->id])->assertStatus(422)->assertExactJson(['code' => 'same_class']);
        $this->postJson($url, ['class_id' => $closed->id])->assertStatus(422)->assertExactJson(['code' => 'class_closed']);
        $this->postJson($url, ['class_id' => $new->id, 'date' => '2026-11-21'])->assertStatus(422)->assertExactJson(['code' => 'invalid_date']);
        $this->postJson($url, ['class_id' => $new->id, 'date' => '2026-02-30'])->assertStatus(422);
        $this->postJson($url, ['class_id' => $new->id, 'date' => '2026-09-15'])->assertStatus(422)->assertExactJson(['code' => 'date_before_enrollment']);
        $this->postJson($url, ['class_id' => 999999])->assertStatus(404)->assertExactJson(['code' => 'class_not_found']);

        $this->assertSame($old->id, StudentEnrollment::query()->firstOrFail()->class_id);
    }

    public function test_withdraw_closes_enrollment_but_keeps_history(): void
    {
        $class = $this->makeClass($this->makeYear(), '7В');
        $student = $this->enrol($class, 'Тоқтаров', 'Әділ');

        $this->postJson("/api/admin/students/{$student->id}/withdraw", ['date' => '2026-11-13'])->assertOk();

        $this->assertFalse($student->refresh()->is_active);
        $this->assertCount(1, $this->getJson("/api/admin/classes/{$class->id}/students?date=2026-11-13")->json('students'));
        $this->assertCount(0, $this->getJson("/api/admin/classes/{$class->id}/students?date=2026-11-14")->json('students'));
        $this->postJson("/api/admin/students/{$student->id}/withdraw")->assertStatus(422)->assertExactJson(['code' => 'not_enrolled']);
    }

    public function test_name_can_be_corrected_but_not_emptied(): void
    {
        $class = $this->makeClass($this->makeYear(), '7В');
        $student = $this->enrol($class, 'Токтаров', 'Адил');

        $this->patchJson("/api/admin/students/{$student->id}", ['last_name' => 'Тоқтаров', 'first_name' => 'Әділ', 'patronymic' => 'Маратұлы'])
            ->assertOk()->assertJsonPath('student.last_name', 'Тоқтаров')->assertJsonPath('student.class_id', $class->id);
        $this->patchJson("/api/admin/students/{$student->id}", ['last_name' => '   '])
            ->assertStatus(422)->assertExactJson(['code' => 'validation_failed', 'fields' => ['last_name']]);
        $this->assertSame('Тоқтаров', $student->refresh()->last_name);
    }

    public function test_teacher_cannot_manage_students(): void
    {
        $class = $this->makeClass($this->makeYear(), '7В');
        $student = $this->enrol($class, 'Тоқтаров', 'Әділ');
        $this->actingAs(User::factory()->create());

        $this->getJson("/api/admin/classes/{$class->id}/students")->assertStatus(403);
        $this->postJson("/api/admin/classes/{$class->id}/students", ['last_name' => 'А', 'first_name' => 'Б'])->assertStatus(403);
        $this->patchJson("/api/admin/students/{$student->id}", ['last_name' => 'Х'])->assertStatus(403);
        $this->postJson("/api/admin/students/{$student->id}/withdraw")->assertStatus(403);
    }
}
