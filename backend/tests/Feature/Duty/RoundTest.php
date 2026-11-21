<?php

namespace Tests\Feature\Duty;

use App\Enums\Shift;
use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\AttendanceRoundConflict;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\EnrollmentService;
use Carbon\CarbonImmutable;

class RoundTest extends DutyTestCase
{
    private SchoolClass $class;

    /** @var array<string, int> «Тегі» => id */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->class = $this->classWith('5А', ['Әлібеков Бірінші', 'Сынақов Екінші', 'Үлгіев Үшінші']);
        $this->ids = Student::query()->pluck('id', 'last_name')->all();
        $this->dutyLogin($this->issue());
    }

    /** @param  list<string>  $absent */
    private function save(array $absent, ?string $uuid = null, ?int $revision = null, ?SchoolClass $class = null)
    {
        return $this->putJson('/api/duty/classes/'.($class ?? $this->class)->id.'/round', [
            'client_uuid' => $uuid ?? $this->uuid(),
            'absent_student_ids' => array_map(fn (string $name): int => $this->ids[$name], $absent),
            'revision' => $revision,
            'client_time' => '2026-11-20T08:12:00+05:00',
        ]);
    }

    public function test_roster_is_in_kazakh_order_everyone_present_and_nothing_private_is_exposed(): void
    {
        $response = $this->getJson("/api/duty/classes/{$this->class->id}")->assertOk();

        $response->assertJsonPath('class.name', '5А')->assertJsonPath('round', null);
        $this->assertSame(['Әлібеков', 'Сынақов', 'Үлгіев'], array_column($response->json('students'), 'last_name'));
        $this->assertSame(['present', 'present', 'present'], array_column($response->json('students'), 'status'));
        $this->assertSame(['id', 'last_name', 'first_name', 'patronymic', 'status', 'locked'], array_keys($response->json('students.0')));
    }

    public function test_saving_writes_a_record_for_every_student_and_updates_the_class_list(): void
    {
        $uuid = $this->uuid();
        $response = $this->save(['Сынақов'], $uuid)->assertOk();

        $response->assertJsonPath('stored_as', 'round')->assertJsonPath('round.client_uuid', $uuid)
            ->assertJsonPath('round.revision', 1)->assertJsonPath('round.absent_count', 1)
            ->assertJsonPath('students.1.status', 'absent');
        $this->assertSame(3, AttendanceRecord::query()->count());
        $this->assertSame(1, AttendanceRecord::query()->where('round_status', 'absent')->count());
        $round = AttendanceRound::query()->sole();
        $this->assertSame('2026-11-20 08:12:00', $round->client_time->format('Y-m-d H:i:s'));
        $this->assertSame(1, $round->duty_session_id === null ? 0 : 1);
        $this->assertSame(0, AttendanceAudit::query()->count());

        $this->getJson('/api/duty/session')->assertJsonPath('classes.0.round.absent_count', 1);
        $this->actingAs($this->admin)->getJson('/api/admin/duty-codes')->assertJsonPath('days.0.shifts.0.classes_done', 1);
    }

    public function test_resending_the_same_round_changes_nothing(): void
    {
        $uuid = $this->uuid();
        $this->save(['Сынақов'], $uuid)->assertOk();
        // Байланыс үзіліп, планшет сол деректі қайта жіберді (нұсқаны білмей де).
        $this->save(['Сынақов'], $uuid)->assertOk()->assertJsonPath('round.revision', 1);
        $this->save(['Сынақов'], $uuid, 1)->assertOk()->assertJsonPath('round.revision', 1);

        $this->assertSame(1, AttendanceRound::query()->count());
        $this->assertSame(3, AttendanceRecord::query()->count());
        $this->assertSame(0, AttendanceAudit::query()->count());
    }

    public function test_duty_can_correct_a_saved_class_and_every_change_is_audited(): void
    {
        $uuid = $this->uuid();
        $this->save(['Сынақов'], $uuid)->assertOk();

        $response = $this->save(['Үлгіев'], $uuid, 1)->assertOk();

        $response->assertJsonPath('round.revision', 2)->assertJsonPath('round.absent_count', 1);
        $this->assertSame(['present', 'present', 'absent'], array_column($response->json('students'), 'status'));
        $audit = AttendanceAudit::query()->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertSame(['absent', 'present', 'duty'], [$audit[0]->old_value, $audit[0]->new_value, $audit[0]->changed_role]);
        $this->assertNull($audit[0]->changed_by);
        $this->assertNotNull($audit[0]->duty_session_id);
    }

    public function test_stale_revision_is_refused_so_two_tablets_do_not_overwrite_each_other(): void
    {
        $uuid = $this->uuid();
        $this->save(['Сынақов'], $uuid)->assertOk();
        $this->save(['Сынақов', 'Үлгіев'], $uuid, 1)->assertOk(); // 1-планшет түзеді → 2-нұсқа

        // 2-планшет ескі күйден (1-нұсқа) бастап басқа өзгеріс жібереді.
        $this->save([], $uuid, 1)->assertStatus(409)->assertJsonPath('code', 'round_changed');
        $this->assertSame(2, AttendanceRecord::query()->where('round_status', 'absent')->count());
    }

    public function test_independent_round_from_another_tablet_is_kept_as_a_conflict_not_overwritten(): void
    {
        $this->save(['Сынақов'])->assertOk();

        $other = $this->uuid();
        $this->save(['Әлібеков', 'Үлгіев'], $other)->assertOk()->assertJsonPath('stored_as', 'conflict')
            ->assertJsonPath('round.absent_count', 1);
        $this->save(['Әлібеков', 'Үлгіев'], $other)->assertOk()->assertJsonPath('stored_as', 'conflict');

        $this->assertSame(1, AttendanceRound::query()->count());
        $this->assertSame(1, AttendanceRecord::query()->where('round_status', 'absent')->count());
        $conflict = AttendanceRoundConflict::query()->sole();
        $this->assertEqualsCanonicalizing([$this->ids['Әлібеков'], $this->ids['Үлгіев']], $conflict->absent_student_ids);
        $this->assertNull($conflict->resolved_at);
    }

    public function test_records_touched_by_staff_are_locked_for_duty(): void
    {
        $uuid = $this->uuid();
        $this->save(['Сынақов', 'Үлгіев'], $uuid)->assertOk();
        // 6-кезеңде экраны болады; ереже қазірден сақталуы тиіс.
        AttendanceRecord::query()->where('student_id', $this->ids['Сынақов'])->update(['absence_reason' => 'sick']);
        AttendanceRecord::query()->where('student_id', $this->ids['Үлгіев'])->update(['arrived_lesson' => 2]);
        AttendanceRecord::query()->where('student_id', $this->ids['Әлібеков'])->update(['round_status' => 'absent', 'round_status_source' => 'staff']);

        $roster = $this->getJson("/api/duty/classes/{$this->class->id}")->assertOk();
        $this->assertSame([true, true, true], array_column($roster->json('students'), 'locked'));
        $this->assertStringNotContainsString('sick', $roster->getContent());

        $response = $this->save([], $uuid, 1)->assertOk();
        $this->assertEqualsCanonicalizing(array_values($this->ids), $response->json('kept_locked'));
        $this->assertSame(3, AttendanceRecord::query()->where('round_status', 'absent')->count());
        $this->assertSame(0, AttendanceAudit::query()->count());
    }

    public function test_student_who_joined_after_the_round_is_added_on_the_next_save(): void
    {
        $uuid = $this->uuid();
        $this->save([], $uuid)->assertOk();
        $new = $this->enrol($this->class, 'Жаңаев', 'Төртінші', date: '2026-11-20');
        $this->ids['Жаңаев'] = $new->id;

        $this->save(['Жаңаев'], $uuid, 1)->assertOk()->assertJsonPath('round.absent_count', 1);
        $this->assertSame(4, AttendanceRecord::query()->count());
    }

    public function test_transferred_out_student_keeps_his_record_but_leaves_the_roster(): void
    {
        $uuid = $this->uuid();
        $this->save(['Сынақов'], $uuid)->assertOk();
        $other = $this->classWith('5Ә', []);
        app(EnrollmentService::class)->transfer(Student::query()->find($this->ids['Сынақов']), $other, CarbonImmutable::parse('2026-11-20'));

        $roster = $this->getJson("/api/duty/classes/{$this->class->id}")->assertOk();
        $this->assertSame(['Әлібеков', 'Үлгіев'], array_column($roster->json('students'), 'last_name'));
        $this->assertSame(3, AttendanceRecord::query()->count());
    }

    public function test_foreign_students_other_shift_classes_and_bad_input_are_refused(): void
    {
        $afternoon = $this->classWith('6А', ['Бөтенов Бесінші'], Shift::Afternoon);
        $this->ids['Бөтенов'] = Student::query()->where('last_name', 'Бөтенов')->value('id');

        $this->save(['Бөтенов'])->assertStatus(422)->assertJsonPath('code', 'unknown_students');
        $this->getJson("/api/duty/classes/{$afternoon->id}")->assertNotFound()->assertJsonPath('code', 'class_not_in_shift');
        $this->save([], class: $afternoon)->assertNotFound();
        $this->putJson("/api/duty/classes/{$this->class->id}/round", ['client_uuid' => 'жоқ', 'absent_student_ids' => []])->assertStatus(422);
        $this->putJson("/api/duty/classes/{$this->class->id}/round", ['client_uuid' => $this->uuid()])->assertStatus(422);
        $this->assertSame(0, AttendanceRound::query()->count());
    }

    public function test_nothing_can_be_saved_after_the_shift_ends_or_without_a_code(): void
    {
        $uuid = $this->uuid();
        $this->save(['Сынақов'], $uuid)->assertOk();

        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 14, 0, 1));
        $this->app['auth']->forgetGuards();
        $this->save([], $uuid, 1)->assertUnauthorized()->assertJsonPath('code', 'duty_code_ended');
        $this->assertSame(1, AttendanceRecord::query()->where('round_status', 'absent')->count());

        $this->flushSession();
        $this->defaultCookies = [];
        $this->getJson("/api/duty/classes/{$this->class->id}")->assertUnauthorized()->assertJsonPath('code', 'duty_unauthenticated');
    }
}
