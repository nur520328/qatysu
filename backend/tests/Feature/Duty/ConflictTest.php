<?php

namespace Tests\Feature\Duty;

use App\Models\AttendanceAudit;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRound;
use App\Models\AttendanceRoundConflict;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;

/** Екі планшет бір сыныпты бөлек белгіледі; завуч біреуін таңдайды. */
class ConflictTest extends DutyTestCase
{
    private SchoolClass $class;

    /** @var array<string, int> */
    private array $ids;

    private string $firstUuid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->class = $this->classWith('5А', ['Әлібеков Бірінші', 'Сынақов Екінші', 'Үлгіев Үшінші']);
        $this->ids = Student::query()->pluck('id', 'last_name')->all();
        $code = $this->issue();

        // 1-планшет: Сынақов жоқ. 2-планшет (интернетсіз белгілеп, кейін жеткізді): Әлібеков пен Үлгіев жоқ.
        $this->dutyLogin($code);
        $this->firstUuid = $this->uuid();
        $this->mark($this->firstUuid, ['Сынақов'])->assertJsonPath('stored_as', 'round');
        $this->dutyLogin($code);
        $this->mark($this->uuid(), ['Әлібеков', 'Үлгіев'])->assertJsonPath('stored_as', 'conflict');
    }

    /** @param  list<string>  $absent */
    private function mark(string $uuid, array $absent, ?int $revision = null)
    {
        return $this->putJson("/api/duty/classes/{$this->class->id}/round", [
            'client_uuid' => $uuid, 'revision' => $revision, 'client_time' => '2026-11-20T08:12:00+05:00',
            'absent_student_ids' => array_map(fn (string $n): int => $this->ids[$n], $absent),
        ])->assertOk();
    }

    public function test_admin_sees_both_versions_side_by_side(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/round-conflicts')->assertOk();

        $response->assertJsonCount(1, 'conflicts')
            ->assertJsonPath('conflicts.0.class.name', '5А')->assertJsonPath('conflicts.0.date', '2026-11-20')
            ->assertJsonPath('conflicts.0.student_count', 3)
            ->assertJsonPath('conflicts.0.current.device_number', 1)
            ->assertJsonPath('conflicts.0.incoming.device_number', 2)
            ->assertJsonPath('conflicts.0.incoming.marked_at', '2026-11-20T08:12:00+05:00');
        $this->assertSame(['Сынақов Екінші'], array_column($response->json('conflicts.0.current.absent'), 'name'));
        $this->assertSame(['Әлібеков Бірінші', 'Үлгіев Үшінші'], array_column($response->json('conflicts.0.incoming.absent'), 'name'));
    }

    public function test_keeping_the_current_version_changes_no_marks(): void
    {
        $id = AttendanceRoundConflict::query()->value('id');

        $this->actingAs($this->admin)->postJson("/api/admin/round-conflicts/{$id}/resolve", ['resolution' => 'keep'])
            ->assertOk()->assertExactJson(['conflicts' => []]);

        $this->assertSame([$this->ids['Сынақов']], AttendanceRecord::query()->where('round_status', 'absent')->pluck('student_id')->all());
        $this->assertSame(0, AttendanceAudit::query()->count());
        $conflict = AttendanceRoundConflict::query()->sole();
        $this->assertSame(['keep', $this->admin->id], [$conflict->resolution, $conflict->resolved_by]);
    }

    public function test_choosing_the_incoming_version_applies_it_with_audit_and_locks_it_for_duty(): void
    {
        $id = AttendanceRoundConflict::query()->value('id');

        $this->actingAs($this->admin)->postJson("/api/admin/round-conflicts/{$id}/resolve", ['resolution' => 'replace'])->assertOk();

        $absent = AttendanceRecord::query()->where('round_status', 'absent')->pluck('student_id')->all();
        $this->assertEqualsCanonicalizing([$this->ids['Әлібеков'], $this->ids['Үлгіев']], $absent);
        $audit = AttendanceAudit::query()->get();
        $this->assertCount(3, $audit);
        $this->assertSame(['admin'], $audit->pluck('changed_role')->unique()->all());
        $this->assertSame([$this->admin->id], $audit->pluck('changed_by')->unique()->all());
        $this->assertSame(2, AttendanceRound::query()->sole()->revision);

        // Завуч шешкен соң кезекші оны қайта өзгерте алмайды.
        $this->app['auth']->forgetGuards();
        $response = $this->mark($this->firstUuid, ['Сынақов'], 2);
        $this->assertCount(3, $response->json('kept_locked'));
        $this->assertEqualsCanonicalizing([$this->ids['Әлібеков'], $this->ids['Үлгіев']], AttendanceRecord::query()->where('round_status', 'absent')->pluck('student_id')->all());
    }

    public function test_conflict_is_resolved_once_and_only_by_admin(): void
    {
        $id = AttendanceRoundConflict::query()->value('id');

        $this->actingAs(User::factory()->create());
        $this->getJson('/api/admin/round-conflicts')->assertForbidden();
        $this->postJson("/api/admin/round-conflicts/{$id}/resolve", ['resolution' => 'replace'])->assertForbidden();

        $this->actingAs($this->admin);
        $this->postJson("/api/admin/round-conflicts/{$id}/resolve", ['resolution' => 'other'])->assertStatus(422);
        $this->postJson("/api/admin/round-conflicts/{$id}/resolve", ['resolution' => 'keep'])->assertOk();
        $this->postJson("/api/admin/round-conflicts/{$id}/resolve", ['resolution' => 'replace'])
            ->assertStatus(409)->assertJsonPath('code', 'conflict_already_resolved');
        $this->assertSame([$this->ids['Сынақов']], AttendanceRecord::query()->where('round_status', 'absent')->pluck('student_id')->all());
    }
}
