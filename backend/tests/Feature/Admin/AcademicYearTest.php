<?php

namespace Tests\Feature\Admin;

use App\Models\AcademicYear;
use App\Models\CalendarDay;
use App\Models\User;
use App\Services\CalendarService;

class AcademicYearTest extends AdminTestCase
{
    public function test_creating_first_year_makes_it_current_and_builds_five_day_week_calendar(): void
    {
        $this->postJson('/api/admin/academic-years', [
            'name' => '2026–2027', 'start_date' => '2026-09-01', 'end_date' => '2027-05-25',
        ])->assertCreated()->assertJsonPath('year.is_current', true);

        $year = AcademicYear::query()->firstOrFail();
        // 1 қыркүйек пен 25 мамырды қоса есептегенде 267 күн.
        $this->assertSame(267, CalendarDay::query()->where('academic_year_id', $year->id)->count());

        $calendar = app(CalendarService::class);
        $this->assertTrue($calendar->isSchoolDay('2026-09-01'));   // сейсенбі
        $this->assertTrue($calendar->isSchoolDay('2026-09-04'));   // жұма
        $this->assertFalse($calendar->isSchoolDay('2026-09-05'));  // сенбі
        $this->assertFalse($calendar->isSchoolDay('2026-09-06'));  // жексенбі
        $this->assertTrue($calendar->isSchoolDay('2026-09-07'));   // дүйсенбі
        $this->assertFalse($calendar->isSchoolDay('2026-08-31'));  // оқу жылынан тыс
    }

    public function test_second_year_is_not_current_until_switched(): void
    {
        $first = $this->makeYear();
        $id = $this->postJson('/api/admin/academic-years', [
            'name' => '2027–2028', 'start_date' => '2027-09-01', 'end_date' => '2028-05-25',
        ])->assertCreated()->assertJsonPath('year.is_current', false)->json('year.id');

        $this->postJson("/api/admin/academic-years/{$id}/make-current")->assertOk();

        $this->assertFalse($first->refresh()->is_current);
        $this->assertSame(1, AcademicYear::query()->where('is_current', true)->count());
        $this->getJson('/api/admin/academic-years')->assertOk()->assertJsonPath('years.0.name', '2027–2028');
    }

    public function test_rejects_overlapping_duplicate_and_invalid_ranges(): void
    {
        $this->makeYear();

        $this->postJson('/api/admin/academic-years', ['name' => 'Қабаттасқан', 'start_date' => '2027-05-01', 'end_date' => '2027-12-01'])
            ->assertStatus(409)->assertExactJson(['code' => 'dates_overlap']);
        $this->postJson('/api/admin/academic-years', ['name' => '2026–2027', 'start_date' => '2027-09-01', 'end_date' => '2028-05-25'])
            ->assertStatus(409)->assertExactJson(['code' => 'name_taken']);
        $this->postJson('/api/admin/academic-years', ['name' => 'Кері', 'start_date' => '2028-05-25', 'end_date' => '2027-09-01'])
            ->assertStatus(422)->assertExactJson(['code' => 'invalid_range']);
        $this->postJson('/api/admin/academic-years', ['name' => 'Тым ұзын', 'start_date' => '2027-09-01', 'end_date' => '2029-09-01'])
            ->assertStatus(422)->assertExactJson(['code' => 'invalid_range']);
        $this->postJson('/api/admin/academic-years', ['name' => 'Қате күн', 'start_date' => '01.09.2027', 'end_date' => '2028-05-25'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->assertSame(1, AcademicYear::query()->count());
    }

    public function test_vacation_range_removes_school_days_and_can_be_restored(): void
    {
        $year = $this->makeYear();
        $before = $this->getJson("/api/admin/academic-years/{$year->id}/calendar")->assertOk()->json('school_day_count');

        // Күзгі каникул: 26 қазан (дс) – 1 қараша (жс). Ішінде 5 оқу күні бар.
        $this->putJson("/api/admin/academic-years/{$year->id}/calendar", [
            'from' => '2026-10-26', 'to' => '2026-11-01', 'is_school_day' => false, 'note' => 'Күзгі каникул',
        ])->assertOk()->assertExactJson(['changed' => 7]);

        $after = $this->getJson("/api/admin/academic-years/{$year->id}/calendar")->json();
        $this->assertSame($before - 5, $after['school_day_count']);
        $day = collect($after['days'])->firstWhere('date', '2026-10-28');
        $this->assertSame(['date' => '2026-10-28', 'is_school_day' => false, 'note' => 'Күзгі каникул'], $day);

        // Сенбіні оқу күні ету (ауыстырылған жұмыс күні).
        $this->putJson("/api/admin/academic-years/{$year->id}/calendar", [
            'from' => '2026-11-07', 'to' => '2026-11-07', 'is_school_day' => true, 'note' => 'Ауыстырылған күн',
        ])->assertOk();
        $this->assertTrue(app(CalendarService::class)->isSchoolDay('2026-11-07'));
    }

    public function test_calendar_range_outside_the_year_is_rejected(): void
    {
        $year = $this->makeYear();

        $this->putJson("/api/admin/academic-years/{$year->id}/calendar", ['from' => '2026-08-25', 'to' => '2026-09-02', 'is_school_day' => false])
            ->assertStatus(422)->assertExactJson(['code' => 'invalid_range']);
        $this->putJson("/api/admin/academic-years/{$year->id}/calendar", ['from' => '2026-10-10', 'to' => '2026-10-01', 'is_school_day' => false])
            ->assertStatus(422)->assertExactJson(['code' => 'invalid_range']);
    }

    public function test_teacher_cannot_touch_years_or_calendar(): void
    {
        $year = $this->makeYear();
        $this->actingAs(User::factory()->create());

        $this->getJson('/api/admin/academic-years')->assertStatus(403);
        $this->postJson('/api/admin/academic-years', ['name' => 'X', 'start_date' => '2027-09-01', 'end_date' => '2028-05-25'])->assertStatus(403);
        $this->putJson("/api/admin/academic-years/{$year->id}/calendar", ['from' => '2026-10-26', 'to' => '2026-10-30', 'is_school_day' => false])->assertStatus(403);
    }
}
