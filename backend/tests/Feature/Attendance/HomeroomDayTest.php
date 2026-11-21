<?php

namespace Tests\Feature\Attendance;

use App\Enums\Shift;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Feature\Duty\DutyTestCase;

/** Сынып жетекшісі мен завуч: себеп, рұқсат, түсініктеме, түзету, тарих. Дерек жасанды. */
class HomeroomDayTest extends DutyTestCase
{
    private SchoolClass $class;

    private User $homeroom;

    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeroom = User::factory()->create(['full_name' => 'Сынақ Жетекші']);
        $this->class = $this->classWith('5А', ['Әлібеков Бірінші', 'Сынақов Екінші', 'Үлгіев Үшінші']);
        $this->class->update(['homeroom_user_id' => $this->homeroom->id]);
        $this->ids = Student::query()->pluck('id', 'last_name')->all();

        // Кезекші: Сынақов пен Үлгіев жоқ.
        $this->dutyLogin($this->issue());
        $this->putJson("/api/duty/classes/{$this->class->id}/round", [
            'client_uuid' => $this->uuid(), 'absent_student_ids' => [$this->ids['Сынақов'], $this->ids['Үлгіев']],
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
    }

    private function url(string $name = '', string $date = '2026-11-20'): string
    {
        return "/api/homeroom/classes/{$this->class->id}/days/{$date}".($name === '' ? '' : "/students/{$this->ids[$name]}");
    }

    private function record(string $name): AttendanceRecord
    {
        return AttendanceRecord::query()->where('student_id', $this->ids[$name])->sole();
    }

    public function test_homeroom_sees_the_full_day_with_pending_items(): void
    {
        $response = $this->actingAs($this->homeroom)->getJson($this->url())->assertOk();

        $response->assertJsonPath('class.name', '5А')->assertJsonPath('editable', true)->assertJsonPath('is_school_day', true)
            ->assertJsonPath('duty.received_late', false)->assertJsonPath('max_lessons', 7);
        $this->assertSame(['present', 'absent', 'absent'], array_column($response->json('students'), 'round_status'));
        $this->assertSame([null, 'reason', 'reason'], array_column($response->json('students'), 'pending'));
    }

    public function test_reason_and_note_are_set_and_audited(): void
    {
        $this->actingAs($this->homeroom);

        $this->patchJson($this->url('Сынақов'), ['absence_reason' => 'sick', 'note' => '  анықтама әкеледі  '])
            ->assertOk()->assertJsonPath('student.absence_reason', 'sick')->assertJsonPath('student.note', 'анықтама әкеледі')
            ->assertJsonPath('student.pending', null);

        $audit = AttendanceAudit::query()->orderBy('id')->get();
        $this->assertSame(['absence_reason', 'note'], $audit->pluck('field')->all());
        $this->assertSame(['homeroom'], $audit->pluck('changed_role')->unique()->all());
        $this->assertSame($this->homeroom->id, $this->record('Сынақов')->updated_by);

        // Сол мән қайта жіберілсе, тарихта артық жол пайда болмайды.
        $this->patchJson($this->url('Сынақов'), ['absence_reason' => 'sick'])->assertOk();
        $this->assertSame(2, AttendanceAudit::query()->count());
    }

    public function test_inconsistent_combinations_are_refused(): void
    {
        $this->actingAs($this->homeroom);

        // «Бар» оқушыға не келген оқушыға «ауырды» қойылмайды; кетпеген оқушыға рұқсат қойылмайды.
        $this->patchJson($this->url('Әлібеков'), ['absence_reason' => 'sick'])->assertStatus(422)->assertJsonPath('code', 'reason_needs_full_absence');
        $this->patchJson($this->url('Сынақов'), ['arrived_lesson' => 2, 'absence_reason' => 'excused'])->assertStatus(422)->assertJsonPath('code', 'reason_needs_full_absence');
        $this->patchJson($this->url('Сынақов'), ['leave_permission' => 'permitted'])->assertStatus(422)->assertJsonPath('code', 'permission_needs_leave');
        $this->patchJson($this->url('Сынақов'), ['arrived_lesson' => 5, 'left_lesson' => 3])->assertStatus(422)->assertJsonPath('code', 'leave_before_arrival');
        $this->patchJson($this->url('Сынақов'), ['absence_reason' => 'lazy'])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->patchJson($this->url('Сынақов'), ['note' => str_repeat('а', 501)])->assertStatus(422);
        $this->assertSame(0, AttendanceAudit::query()->count());
    }

    public function test_dependent_fields_are_cleared_when_the_base_fact_changes(): void
    {
        $this->actingAs($this->homeroom);
        $this->patchJson($this->url('Сынақов'), ['absence_reason' => 'unexcused'])->assertOk();

        // Оқушы шынында 3-сабақтан келген екен: «себепсіз» белгісі өзі алынады.
        $this->patchJson($this->url('Сынақов'), ['arrived_lesson' => 3])
            ->assertOk()->assertJsonPath('student.absence_reason', null)->assertJsonPath('student.arrived_lesson', 3);

        // Кету мен рұқсат; кету алынса, рұқсат та алынады.
        $this->patchJson($this->url('Сынақов'), ['left_lesson' => 5, 'leave_permission' => 'unpermitted'])
            ->assertOk()->assertJsonPath('student.pending', null);
        $this->patchJson($this->url('Сынақов'), ['left_lesson' => null])
            ->assertOk()->assertJsonPath('student.leave_permission', null);

        $fields = AttendanceAudit::query()->orderBy('id')->pluck('field')->all();
        $this->assertSame(['absence_reason', 'arrived_lesson', 'absence_reason', 'left_lesson', 'leave_permission', 'left_lesson', 'leave_permission'], $fields);
    }

    public function test_correcting_the_duty_mark_locks_it_for_duty_and_shows_in_history(): void
    {
        $this->actingAs($this->homeroom);
        $this->patchJson($this->url('Үлгіев'), ['round_status' => 'present'])->assertOk()->assertJsonPath('student.round_status', 'present');

        $record = $this->record('Үлгіев');
        $this->assertSame('staff', $record->round_status_source);
        $this->assertTrue($record->isLockedForDuty());

        $history = $this->getJson($this->url('Үлгіев').'/history')->assertOk();
        $history->assertJsonPath('created.by_duty', true)->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.field', 'round_status')->assertJsonPath('changes.0.old', 'absent')->assertJsonPath('changes.0.new', 'present')
            ->assertJsonPath('changes.0.role', 'homeroom')->assertJsonPath('changes.0.by', 'Сынақ Жетекші');
        $this->getJson($this->url('Әлібеков').'/history')->assertOk()->assertJsonCount(0, 'changes');
    }

    public function test_homeroom_edits_only_recent_days_admin_edits_any_past_day(): void
    {
        // Екі апта бұрынғы аралау.
        $old = AttendanceRound::query()->create(['class_id' => $this->class->id, 'date' => '2026-11-05', 'shift' => Shift::Morning, 'client_uuid' => $this->uuid()]);
        AttendanceRecord::query()->create(['round_id' => $old->id, 'student_id' => $this->ids['Сынақов'], 'round_status' => 'absent']);
        $patch = ['absence_reason' => 'excused'];

        $this->actingAs($this->homeroom);
        $this->getJson($this->url('', '2026-11-05'))->assertOk()->assertJsonPath('editable', false)->assertJsonPath('students.1.pending', 'reason');
        $this->patchJson($this->url('Сынақов', '2026-11-05'), $patch)->assertStatus(422)->assertJsonPath('code', 'date_not_editable');
        $this->patchJson($this->url('Сынақов', '2026-11-06'), $patch)->assertOk(); // 14 күн шегінде (жазба жаңадан ашылады)
        $this->patchJson($this->url('Сынақов', '2026-11-23'), $patch)->assertStatus(422)->assertJsonPath('code', 'date_not_editable'); // болашақ
        $this->patchJson($this->url('Сынақов', '2026-11-21'), $patch)->assertStatus(422); // сенбі: болашақ әрі оқу күні емес

        $this->actingAs($this->admin);
        $this->getJson($this->url('', '2026-11-05'))->assertJsonPath('editable', true);
        $this->patchJson($this->url('Сынақов', '2026-11-05'), $patch)->assertOk()->assertJsonPath('student.absence_reason', 'excused');
        $this->assertSame('admin', AttendanceAudit::query()->orderByDesc('id')->value('changed_role'));
        $this->patchJson($this->url('Сынақов', '2026-11-07'), $patch)->assertStatus(422)->assertJsonPath('code', 'not_school_day'); // сенбі
        $this->getJson($this->url('', 'кеше'))->assertStatus(422)->assertJsonPath('code', 'invalid_date');
    }

    public function test_day_without_a_duty_round_can_still_be_filled_in(): void
    {
        $this->actingAs($this->homeroom);
        $this->getJson($this->url('', '2026-11-19'))->assertOk()->assertJsonPath('duty', null)->assertJsonPath('students.0.round_status', null);

        $this->patchJson($this->url('Әлібеков', '2026-11-19'), ['round_status' => 'absent', 'absence_reason' => 'sick'])
            ->assertOk()->assertJsonPath('student.round_status', 'absent')->assertJsonPath('student.absence_reason', 'sick');

        $round = AttendanceRound::query()->whereDate('date', '2026-11-19')->sole();
        $this->assertFalse($round->duty_done);
        $this->getJson($this->url('', '2026-11-19'))->assertJsonPath('duty', null);
        $this->getJson($this->url('Әлібеков', '2026-11-19').'/history')->assertJsonPath('created.by_duty', false);
    }

    public function test_only_the_class_homeroom_or_admin_gets_in(): void
    {
        $other = User::factory()->create();
        $foreign = $this->classWith('5Ә', ['Бөтенов Төртінші']);

        $this->actingAs($other);
        $this->getJson($this->url())->assertForbidden();
        $this->patchJson($this->url('Сынақов'), ['absence_reason' => 'sick'])->assertForbidden();
        $this->getJson($this->url('Сынақов').'/history')->assertForbidden();

        // Жетекші басқа сыныпқа да, басқа сыныптың оқушысын өз сыныбы арқылы да өзгерте алмайды.
        $this->actingAs($this->homeroom);
        $this->getJson("/api/homeroom/classes/{$foreign->id}/days/2026-11-20")->assertForbidden();
        $stranger = Student::query()->where('last_name', 'Бөтенов')->value('id');
        $this->patchJson("/api/homeroom/classes/{$this->class->id}/days/2026-11-20/students/{$stranger}", ['round_status' => 'absent'])
            ->assertStatus(422)->assertJsonPath('code', 'student_not_in_class');
        $this->assertNull($this->record('Сынақов')->absence_reason);
    }

    public function test_pending_list_shows_what_the_homeroom_still_has_to_decide(): void
    {
        $this->classWith('5Ә', ['Бөтенов Төртінші'])->update(['homeroom_user_id' => User::factory()->create()->id]);
        $this->actingAs($this->homeroom);
        $this->patchJson($this->url('Сынақов'), ['absence_reason' => 'sick'])->assertOk();
        $this->patchJson($this->url('Әлібеков'), ['left_lesson' => 4])->assertOk();

        $items = $this->getJson('/api/homeroom/pending')->assertOk()->json('items');
        $this->assertSame(
            [['Әлібеков Бірінші', 'permission'], ['Үлгіев Үшінші', 'reason']],
            array_map(static fn (array $i): array => [$i['student']['name'], $i['kind']], $items),
        );
        $this->assertSame(['5А', '2026-11-20'], [$items[0]['class']['name'], $items[0]['date']]);

        // Мерзімнен ескі жазба тізімге кірмейді; жетекші емес мұғалімде тізім бос; завуч бәрін көреді.
        Setting::query()->create(['key' => 'homeroom_edit_days', 'value' => '0']);
        $this->travelTo(CarbonImmutable::create(2026, 11, 23, 9));
        $this->getJson('/api/homeroom/pending')->assertExactJson(['items' => []]);
        $this->actingAs(User::factory()->create())->getJson('/api/homeroom/pending')->assertExactJson(['items' => []]);
    }
}
