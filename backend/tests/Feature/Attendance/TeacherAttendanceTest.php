<?php

namespace Tests\Feature\Attendance;

use App\Enums\Shift;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\CalendarService;
use Carbon\CarbonImmutable;
use Tests\Feature\Duty\DutyTestCase;

/** Пән мұғалімі: кешігу мен кетіп қалуды тіркеу. Дерек жасанды. */
class TeacherAttendanceTest extends DutyTestCase
{
    private SchoolClass $class;

    private User $teacher;

    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->class = $this->classWith('7Ә', ['Әлібеков Бірінші', 'Сынақов Екінші', 'Үлгіев Үшінші'], Shift::Afternoon);
        $this->classWith('5А', ['Сынақбаев Төртінші', 'Бөтенов Бесінші']);
        $this->ids = Student::query()->pluck('id', 'last_name')->all();
        $this->teacher = User::factory()->create();
    }

    /** Кезекші 7Ә-ні аралап, көрсетілгендерді «жоқ» деп белгілейді. */
    private function dutyRound(array $absent): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 14, 10));
        $this->dutyLogin($this->issue(Shift::Afternoon));
        $this->putJson("/api/duty/classes/{$this->class->id}/round", [
            'client_uuid' => $this->uuid(), 'absent_student_ids' => array_map(fn (string $n): int => $this->ids[$n], $absent),
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
    }

    private function record(string $name): AttendanceRecord
    {
        return AttendanceRecord::query()->where('student_id', $this->ids[$name])->sole();
    }

    public function test_teacher_gets_all_open_classes_and_todays_context(): void
    {
        $this->makeClass($this->year, '9А')->update(['is_active' => false]);

        $response = $this->actingAs($this->teacher)->getJson('/api/teacher/classes')->assertOk();

        $response->assertJsonPath('date', '2026-11-20')->assertJsonPath('is_school_day', true)->assertJsonPath('max_lessons', 7);
        $this->assertSame(['5А', '7Ә'], array_column($response->json('classes'), 'name'));
    }

    public function test_class_list_shows_marks_but_never_reasons_notes_or_permissions(): void
    {
        $this->dutyRound(['Сынақов']);
        $this->record('Сынақов')->update(['absence_reason' => 'sick', 'note' => 'анықтама әкеледі']);
        $this->record('Үлгіев')->update(['left_lesson' => 4, 'leave_permission' => 'unpermitted']);

        $response = $this->actingAs($this->teacher)->getJson("/api/teacher/classes/{$this->class->id}")->assertOk();

        $this->assertSame(['present', 'absent', 'present'], array_column($response->json('students'), 'round_status'));
        $response->assertJsonPath('students.2.left_lesson', 4);
        $this->assertSame(
            ['id', 'last_name', 'first_name', 'patronymic', 'class', 'round_status', 'arrived_lesson', 'left_lesson', 'can_undo_arrival', 'can_undo_leave'],
            array_keys($response->json('students.0')),
        );
        foreach (['sick', 'анықтама', 'unpermitted', 'absence_reason', 'note'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
    }

    public function test_search_by_surname_needs_three_letters_and_shows_the_class(): void
    {
        $this->actingAs($this->teacher);
        $this->getJson('/api/teacher/students?q=Сы')->assertStatus(422);

        $response = $this->getJson('/api/teacher/students?q=сын')->assertOk();
        $found = array_map(static fn (array $s): string => $s['last_name'].' '.$s['class']['name'], $response->json('students'));
        $this->assertSame(['Сынақбаев 5А', 'Сынақов 7Ә'], $found);

        // Жүйелік таңбалар іздеуді бұзбайды.
        $this->getJson('/api/teacher/students?q='.urlencode('%__'))->assertOk()->assertJsonCount(0, 'students');
    }

    public function test_late_arrival_is_registered_without_touching_the_duty_mark(): void
    {
        $this->dutyRound(['Сынақов']);
        $this->actingAs($this->teacher);

        $response = $this->postJson("/api/teacher/students/{$this->ids['Сынақов']}/arrival", ['lesson' => 2])->assertOk();

        $response->assertJsonPath('student.arrived_lesson', 2)->assertJsonPath('student.round_status', 'absent')
            ->assertJsonPath('student.can_undo_arrival', true);
        $record = $this->record('Сынақов');
        $this->assertSame([2, $this->teacher->id, 'duty'], [$record->arrived_lesson, $record->arrived_by, $record->round_status_source]);
        $audit = AttendanceAudit::query()->sole();
        $this->assertSame(['arrived_lesson', null, '2', 'teacher', $this->teacher->id], [$audit->field, $audit->old_value, $audit->new_value, $audit->changed_role, $audit->changed_by]);
        $this->assertSame(2, AttendanceRound::query()->sole()->revision);
    }

    public function test_leaving_is_registered_and_cannot_be_before_arrival(): void
    {
        $this->dutyRound(['Сынақов']);
        $this->actingAs($this->teacher);
        $this->postJson("/api/teacher/students/{$this->ids['Сынақов']}/arrival", ['lesson' => 3])->assertOk();

        $this->postJson("/api/teacher/students/{$this->ids['Сынақов']}/leave", ['lesson' => 2])
            ->assertStatus(422)->assertJsonPath('code', 'leave_before_arrival');
        $this->postJson("/api/teacher/students/{$this->ids['Сынақов']}/leave", ['lesson' => 5])
            ->assertOk()->assertJsonPath('student.left_lesson', 5)->assertJsonPath('student.can_undo_leave', true);
        $this->postJson("/api/teacher/students/{$this->ids['Үлгіев']}/leave", ['lesson' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'lesson_out_of_range');
        $this->postJson("/api/teacher/students/{$this->ids['Үлгіев']}/arrival", ['lesson' => 8])
            ->assertStatus(422)->assertJsonPath('code', 'lesson_out_of_range');
    }

    public function test_teacher_undoes_only_own_entry_and_not_after_homeroom_ruled(): void
    {
        $this->dutyRound(['Сынақов']);
        $other = User::factory()->create();
        $this->actingAs($this->teacher)->postJson("/api/teacher/students/{$this->ids['Үлгіев']}/leave", ['lesson' => 4])->assertOk();

        // Басқа мұғалім оны өзгерте де, өшіре де алмайды.
        $this->actingAs($other);
        $this->postJson("/api/teacher/students/{$this->ids['Үлгіев']}/leave", ['lesson' => 6])->assertStatus(409)->assertJsonPath('code', 'already_registered');
        $this->deleteJson("/api/teacher/students/{$this->ids['Үлгіев']}/leave")->assertStatus(409)->assertJsonPath('code', 'cannot_undo');
        $this->getJson("/api/teacher/classes/{$this->class->id}")->assertJsonPath('students.2.can_undo_leave', false);

        // Жетекші рұқсат белгісін қойған соң тіркеген мұғалімнің өзі де өшіре алмайды.
        $this->record('Үлгіев')->update(['leave_permission' => 'permitted']);
        $this->actingAs($this->teacher)->deleteJson("/api/teacher/students/{$this->ids['Үлгіев']}/leave")->assertStatus(409);
        $this->assertSame(4, $this->record('Үлгіев')->left_lesson);

        // Рұқсат қойылмаған болса, өз жазбасын өшіреді; ол да тарихта қалады.
        $this->record('Үлгіев')->update(['leave_permission' => null]);
        $this->deleteJson("/api/teacher/students/{$this->ids['Үлгіев']}/leave")->assertOk()->assertJsonPath('student.left_lesson', null);
        $this->assertNull($this->record('Үлгіев')->left_by);
        $last = AttendanceAudit::query()->orderByDesc('id')->first();
        $this->assertSame(['left_lesson', '4', null], [$last->field, $last->old_value, $last->new_value]);
        $this->deleteJson("/api/teacher/students/{$this->ids['Үлгіев']}/leave")->assertNotFound()->assertJsonPath('code', 'not_registered');
    }

    public function test_student_with_a_reason_set_by_homeroom_is_not_overridden_by_teacher(): void
    {
        $this->dutyRound(['Сынақов']);
        $this->record('Сынақов')->update(['absence_reason' => 'sick']);

        $this->actingAs($this->teacher)->postJson("/api/teacher/students/{$this->ids['Сынақов']}/arrival", ['lesson' => 3])
            ->assertStatus(409)->assertJsonPath('code', 'reason_already_set');
        $this->assertNull($this->record('Сынақов')->arrived_lesson);
    }

    public function test_teacher_can_register_before_the_duty_round_and_duty_later_continues_that_day(): void
    {
        $this->actingAs($this->teacher);
        $this->postJson("/api/teacher/students/{$this->ids['Үлгіев']}/leave", ['lesson' => 3])->assertOk()->assertJsonPath('student.round_status', 'present');
        $this->postJson("/api/teacher/students/{$this->ids['Сынақов']}/arrival", ['lesson' => 2])->assertOk()->assertJsonPath('student.round_status', 'absent');

        $round = AttendanceRound::query()->sole();
        $this->assertFalse($round->duty_done);
        $this->assertSame(2, AttendanceRecord::query()->count());
        // Тіркелмеген оқушыға әлі белгі жоқ.
        $this->getJson("/api/teacher/classes/{$this->class->id}")->assertJsonPath('students.0.round_status', null);

        // Кезекші келді: оған сынып «аралынбаған», ал мұғалім тіркеген оқушылар құлыптаулы.
        $this->app['auth']->forgetGuards();
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 14, 10));
        $this->dutyLogin($this->issue(Shift::Afternoon));
        $state = $this->getJson('/api/duty/session')->assertOk();
        $this->assertNull($state->json('classes.0.round'));
        $roster = $this->getJson("/api/duty/classes/{$this->class->id}")->assertOk()->assertJsonPath('round', null);
        $this->assertSame([false, true, true], array_column($roster->json('students'), 'locked'));
        $this->assertSame(['present', 'absent', 'present'], array_column($roster->json('students'), 'status'));

        $uuid = $this->uuid();
        $saved = $this->putJson("/api/duty/classes/{$this->class->id}/round", [
            'client_uuid' => $uuid, 'absent_student_ids' => [$this->ids['Әлібеков'], $this->ids['Үлгіев']],
        ])->assertOk();

        // Қақтығыс емес: сол күннің жазбасы жалғасты. Мұғалім көрген Үлгіев «бар» күйінде қалды.
        $saved->assertJsonPath('stored_as', 'round')->assertJsonPath('round.client_uuid', $uuid)->assertJsonPath('round.absent_count', 2);
        $this->assertEqualsCanonicalizing([$this->ids['Сынақов'], $this->ids['Үлгіев']], $saved->json('kept_locked'));
        $this->assertSame(1, AttendanceRound::query()->count());
        $this->assertTrue(AttendanceRound::query()->sole()->duty_done);
        $this->assertSame(3, AttendanceRecord::query()->count());
        $this->assertSame(3, $this->record('Үлгіев')->left_lesson);
    }

    public function test_registration_is_only_for_today_school_day_and_enrolled_students(): void
    {
        $this->actingAs($this->teacher);
        app(CalendarService::class)->setRange($this->year, CarbonImmutable::parse('2026-11-20'), CarbonImmutable::parse('2026-11-20'), false, 'мереке');

        $this->getJson('/api/teacher/classes')->assertJsonPath('is_school_day', false);
        $this->postJson("/api/teacher/students/{$this->ids['Сынақов']}/arrival", ['lesson' => 2])
            ->assertStatus(422)->assertJsonPath('code', 'not_school_day');

        $gone = Student::query()->create(['last_name' => 'Кеткенов', 'first_name' => 'Алтыншы']);
        $this->postJson("/api/teacher/students/{$gone->id}/arrival", ['lesson' => 2])->assertNotFound()->assertJsonPath('code', 'student_not_in_class');
        $this->assertSame(0, AttendanceRound::query()->count());
    }

    public function test_guests_duty_tablets_and_unfinished_accounts_have_no_access(): void
    {
        $url = "/api/teacher/students/{$this->ids['Сынақов']}/arrival";
        $this->postJson($url, ['lesson' => 2])->assertUnauthorized();

        $this->dutyRound([]);
        $this->dutyLogin($this->issue(Shift::Afternoon));
        $this->postJson($url, ['lesson' => 2])->assertUnauthorized();
        $this->getJson('/api/teacher/classes')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->mustChangePassword()->create())->postJson($url, ['lesson' => 2])->assertForbidden();
    }
}
