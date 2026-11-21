<?php

namespace Tests\Feature\Attendance;

use App\Enums\Shift;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\EnrollmentService;
use Carbon\CarbonImmutable;
use Tests\Feature\Duty\DutyTestCase;

/** Завучтың күнделікті жиынтығы. Күн: жұма 2026-11-20. Дерек жасанды. */
class DailySummaryTest extends DutyTestCase
{
    private SchoolClass $a;

    private SchoolClass $b;

    private SchoolClass $c;

    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $homeroom = User::factory()->create(['full_name' => 'Сынақ Жетекші']);
        $this->a = $this->classWith('5А', ['Әлібеков Бірінші', 'Сынақов Екінші', 'Үлгіев Үшінші', 'Жасандиев Төртінші', 'Мысалов Бесінші']);
        $this->a->update(['homeroom_user_id' => $homeroom->id]);
        $this->b = $this->classWith('6Ә', ['Ойданов Алтыншы', 'Тексеров Жетінші']);
        $this->c = $this->classWith('7Б', ['Қиялов Сегізінші', 'Үлгібаев Тоғызыншы'], Shift::Afternoon);
        $this->ids = Student::query()->pluck('id', 'last_name')->all();

        // Кезекші 5А-ны аралады: Сынақов, Үлгіев, Жасандиев жоқ. 6Ә мен 7Б аралынбады.
        $this->dutyLogin($this->issue());
        $this->putJson("/api/duty/classes/{$this->a->id}/round", [
            'client_uuid' => $this->uuid(),
            'absent_student_ids' => [$this->ids['Сынақов'], $this->ids['Үлгіев'], $this->ids['Жасандиев']],
        ])->assertOk();
        $this->asBrowserOf($this->admin);

        // Үлгіев 2-сабақтан келді; Әлібеков 4-сабақтан кетті; Сынақов ауырды; 6Ә-дағы Ойданов 3-сабақтан кетті (рұқсатпен).
        $this->postJson("/api/teacher/students/{$this->ids['Үлгіев']}/arrival", ['lesson' => 2])->assertOk();
        $this->postJson("/api/teacher/students/{$this->ids['Әлібеков']}/leave", ['lesson' => 4])->assertOk();
        $this->patchJson($this->day($this->a, 'Сынақов'), ['absence_reason' => 'sick', 'note' => 'анықтама бар'])->assertOk();
        $this->postJson("/api/teacher/students/{$this->ids['Ойданов']}/leave", ['lesson' => 3])->assertOk();
        $this->patchJson($this->day($this->b, 'Ойданов'), ['leave_permission' => 'permitted'])->assertOk();
    }

    private function asBrowserOf(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->actingAs($user);
    }

    private function day(SchoolClass $class, string $name, string $date = '2026-11-20'): string
    {
        return "/api/homeroom/classes/{$class->id}/days/{$date}/students/{$this->ids[$name]}";
    }

    /** @return array<string, mixed> */
    private function shift(array $json, string $shift): array
    {
        return collect($json['shifts'])->firstWhere('shift', $shift);
    }

    public function test_summary_counts_today_by_shift_and_class(): void
    {
        $json = $this->getJson('/api/admin/daily-summary')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('date', '2026-11-20')->assertJsonPath('is_today', true)->assertJsonPath('is_school_day', true)
            ->assertJsonPath('previous_school_day', '2026-11-19')->assertJsonPath('next_school_day', null)
            ->assertJsonPath('open_conflicts', 0)->json();

        $morning = $this->shift($json, 'morning');
        $this->assertSame(
            ['ended' => false, 'classes_total' => 2, 'classes_surveyed' => 1, 'students' => 7, 'unmarked' => 1, 'absent' => 2, 'late' => 1, 'left' => 2],
            array_intersect_key($morning, array_flip(['ended', 'classes_total', 'classes_surveyed', 'students', 'unmarked', 'absent', 'late', 'left'])),
        );
        $this->assertSame(['sick' => 1, 'excused' => 0, 'unexcused' => 0, 'pending' => 1], $morning['absent_by_reason']);
        $this->assertSame(['permitted' => 1, 'unpermitted' => 0, 'pending' => 1], $morning['left_by_permission']);

        [$a, $b] = $morning['classes'];
        $this->assertSame(['5А', 'Сынақ Жетекші', 5, true, 0, 2, 1, 1, 2], [$a['name'], $a['homeroom'], $a['students'], $a['surveyed'], $a['unmarked'], $a['absent'], $a['late'], $a['left'], $a['pending']]);
        $this->assertNotNull($a['saved_at']);
        // Тізім қазақ әліпбиі ретімен; мұғалім белгілеген, кезекші «бар» деген Мысалов тізімде жоқ.
        $this->assertSame(['Әлібеков Бірінші', 'Жасандиев Төртінші', 'Сынақов Екінші', 'Үлгіев Үшінші'], array_column($a['people'], 'name'));
        $byName = array_column($a['people'], null, 'name');
        $this->assertSame([false, null, 4, null, 'permission'], array_values(array_intersect_key($byName['Әлібеков Бірінші'], array_flip(['full_absence', 'arrived_lesson', 'left_lesson', 'leave_permission', 'pending']))));
        $this->assertSame([true, 'sick', 'анықтама бар', null], [$byName['Сынақов Екінші']['full_absence'], $byName['Сынақов Екінші']['absence_reason'], $byName['Сынақов Екінші']['note'], $byName['Сынақов Екінші']['pending']]);
        $this->assertSame(['present', 'absent', 'absent', 'absent'], array_column($a['people'], 'round_status'));
        $this->assertSame([true, 'reason'], [$byName['Жасандиев Төртінші']['full_absence'], $byName['Жасандиев Төртінші']['pending']]);
        $this->assertSame([false, 2, null], [$byName['Үлгіев Үшінші']['full_absence'], $byName['Үлгіев Үшінші']['arrived_lesson'], $byName['Үлгіев Үшінші']['pending']]);

        // Аралынбаған сынып: мұғалім тіркегені саналады, қалған оқушы «белгіленбеген», «бар» емес.
        $this->assertSame(['6Ә', null, 2, false, null, 1, 0, 0, 1, 0], [$b['name'], $b['homeroom'], $b['students'], $b['surveyed'], $b['saved_at'], $b['unmarked'], $b['absent'], $b['late'], $b['left'], $b['pending']]);
        $this->assertSame(['Ойданов Алтыншы'], array_column($b['people'], 'name'));

        $afternoon = $this->shift($json, 'afternoon');
        $this->assertSame([1, 0, 2, 2, 0, []], [$afternoon['classes_total'], $afternoon['classes_surveyed'], $afternoon['students'], $afternoon['unmarked'], $afternoon['absent'], $afternoon['classes'][0]['people']]);
    }

    public function test_shift_is_marked_ended_after_its_end_time(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 13, 11));
        $json = $this->getJson('/api/admin/daily-summary')->assertOk()->json();

        $this->assertTrue($this->shift($json, 'morning')['ended']);
        $this->assertFalse($this->shift($json, 'afternoon')['ended']);
    }

    public function test_past_day_keeps_its_own_roster_and_classes(): void
    {
        // Дүйсенбі 2026-11-23: Мысалов 6Ә-ға ауысты, 7Б жабылды. Жұманың жиынтығы өзгермеуі тиіс.
        $this->travelTo(CarbonImmutable::create(2026, 11, 23, 9));
        app(EnrollmentService::class)->transfer(Student::query()->findOrFail($this->ids['Мысалов']), $this->b, CarbonImmutable::parse('2026-11-23'));
        $this->c->update(['is_active' => false]);

        $friday = $this->getJson('/api/admin/daily-summary?date=2026-11-20')->assertOk()
            ->assertJsonPath('is_today', false)->assertJsonPath('next_school_day', '2026-11-23')->json();
        $this->assertSame([5, 2], array_column($this->shift($friday, 'morning')['classes'], 'students'));
        $this->assertTrue($this->shift($friday, 'morning')['ended']);
        // Жабылған сыныпта жұма күні жазба болған жоқ, сондықтан көрінбейді.
        $this->assertSame(0, $this->shift($friday, 'afternoon')['classes_total']);

        $monday = $this->getJson('/api/admin/daily-summary')->assertOk()->assertJsonPath('previous_school_day', '2026-11-20')->json();
        $this->assertSame([4, 3], array_column($this->shift($monday, 'morning')['classes'], 'students'));
        $this->assertSame(7, $this->shift($monday, 'morning')['unmarked']);
    }

    public function test_closed_class_with_records_still_appears_on_that_day(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 23, 9));
        $this->b->update(['is_active' => false]);

        $friday = $this->getJson('/api/admin/daily-summary?date=2026-11-20')->assertOk()->json();
        $this->assertSame(['5А', '6Ә'], array_column($this->shift($friday, 'morning')['classes'], 'name'));
        $monday = $this->getJson('/api/admin/daily-summary')->assertOk()->json();
        $this->assertSame(['5А'], array_column($this->shift($monday, 'morning')['classes'], 'name'));
    }

    public function test_namesakes_in_one_class_are_shown_with_patronymic(): void
    {
        $one = $this->enrol($this->c, 'Егізов', 'Бірдей', 'Аұлы');
        $this->enrol($this->c, 'Егізов', 'Бірдей', 'Бұлы');
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 14, 0));
        $this->postJson("/api/teacher/students/{$one->id}/arrival", ['lesson' => 3])->assertOk();

        $json = $this->getJson('/api/admin/daily-summary')->assertOk()->json();
        $this->assertSame(['Егізов Бірдей Аұлы'], array_column($this->shift($json, 'afternoon')['classes'][0]['people'], 'name'));
    }

    public function test_open_conflict_is_reported(): void
    {
        $code = $this->issue();
        $this->dutyLogin($code);
        $this->putJson("/api/duty/classes/{$this->a->id}/round", ['client_uuid' => $this->uuid(), 'absent_student_ids' => []])->assertOk();
        $this->assertDatabaseCount('attendance_round_conflicts', 1);
        $this->asBrowserOf($this->admin);

        $this->getJson('/api/admin/daily-summary')->assertOk()->assertJsonPath('open_conflicts', 1);
        $this->getJson('/api/admin/daily-summary?date=2026-11-19')->assertOk()->assertJsonPath('open_conflicts', 0);
    }

    public function test_non_school_day_and_day_outside_any_year(): void
    {
        $this->getJson('/api/admin/daily-summary?date=2026-11-15')->assertOk()
            ->assertJsonPath('is_school_day', false)->assertJsonPath('previous_school_day', '2026-11-13')->assertJsonPath('next_school_day', '2026-11-16');

        $old = $this->getJson('/api/admin/daily-summary?date=2025-03-03')->assertOk()->assertJsonPath('is_school_day', false)
            ->assertJsonPath('previous_school_day', null)->assertJsonPath('next_school_day', '2026-09-01')->json();
        $this->assertSame([0, 0], array_column($old['shifts'], 'classes_total'));
    }

    public function test_holiday_today_is_not_a_school_day(): void
    {
        app(CalendarService::class)->setRange($this->year, CarbonImmutable::parse('2026-11-18'), CarbonImmutable::parse('2026-11-19'), false, 'каникул');

        $this->getJson('/api/admin/daily-summary')->assertOk()->assertJsonPath('previous_school_day', '2026-11-17');
    }

    public function test_future_and_malformed_dates_are_rejected(): void
    {
        $this->getJson('/api/admin/daily-summary?date=2026-11-21')->assertStatus(422)->assertJsonPath('code', 'date_in_future');
        foreach (['2026-13-01', '2026-02-30', '20.11.2026', 'today'] as $bad) {
            $this->getJson('/api/admin/daily-summary?date='.$bad)->assertStatus(422)->assertJsonPath('code', 'invalid_date');
        }
    }

    public function test_only_admin_sees_the_summary(): void
    {
        $this->asBrowserOf(User::factory()->create());
        $this->getJson('/api/admin/daily-summary')->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/daily-summary')->assertStatus(401);
    }
}
