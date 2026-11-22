<?php

namespace Tests\Feature\Reports;

use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\EnrollmentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Duty\DutyTestCase;

/** Айлық есеп және жетекшінің айлық қорытындысы. Ай: 2026 жылғы қараша; «қазір» жұма 20 қараша, 08:10. Дерек жасанды. */
class MonthlyReportTest extends DutyTestCase
{
    private SchoolClass $a;

    private SchoolClass $b;

    private SchoolClass $c;

    private User $homeroom;

    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->homeroom = User::factory()->create(['full_name' => 'Сынақ Жетекші']);
        $this->a = $this->classWith('5А', ['Әлібеков Бірінші', 'Сынақов Екінші', 'Үлгіев Үшінші']);
        $this->a->update(['homeroom_user_id' => $this->homeroom->id]);
        $this->b = $this->classWith('5Ә', ['Ойданов Төртінші', 'Тексеров Бесінші']);
        $this->c = $this->classWith('7Б', ['Қиялов Алтыншы', 'Жасандиев Жетінші']);
        $this->ids = Student::query()->pluck('id', 'last_name')->all();

        // Қазан (басқа ай): қарашаның есебіне кірмеуі тиіс.
        $this->mark($this->a, '2026-10-30', ['Әлібеков' => ['round_status' => 'absent']]);
        // 1-апта (2–6 қараша).
        $this->mark($this->a, '2026-11-03', ['Әлібеков' => ['round_status' => 'absent', 'absence_reason' => 'unexcused']]);
        $this->mark($this->c, '2026-11-03');
        // 2-апта (9–13 қараша).
        $this->mark($this->a, '2026-11-10', ['Әлібеков' => ['round_status' => 'absent', 'absence_reason' => 'unexcused'], 'Сынақов' => ['round_status' => 'absent', 'absence_reason' => 'sick']]);
        $this->mark($this->b, '2026-11-10');
        // 3-апта (16–20 қараша).
        $this->mark($this->a, '2026-11-17', ['Үлгіев' => ['round_status' => 'absent', 'arrived_lesson' => 2]]);
        $this->mark($this->c, '2026-11-17', ['Жасандиев' => ['round_status' => 'absent']]);
        $this->actingAs($this->admin);
    }

    /** @param  array<string, array<string, mixed>>  $overrides  тегі => өзгеше өрістер */
    private function mark(SchoolClass $class, string $date, array $overrides = []): void
    {
        $roundId = DB::table('attendance_rounds')->insertGetId([
            'class_id' => $class->id, 'date' => $date, 'shift' => $class->shift->value, 'client_uuid' => (string) Str::uuid(),
            'revision' => 1, 'received_late' => false, 'duty_done' => true, 'created_at' => $date.' 08:30:00', 'updated_at' => $date.' 08:30:00',
        ]);
        $names = DB::table('student_enrollments')->join('students', 'students.id', '=', 'student_enrollments.student_id')
            ->where('class_id', $class->id)->pluck('students.id', 'students.last_name');
        foreach ($names as $name => $studentId) {
            DB::table('attendance_records')->insert(($overrides[$name] ?? []) + [
                'round_id' => $roundId, 'student_id' => $studentId, 'round_status' => 'present', 'round_status_source' => 'duty',
                'created_at' => $date.' 08:30:00', 'updated_at' => $date.' 08:30:00',
            ]);
        }
    }

    /** @param  array<string, mixed>  $row */
    private function pick(array $row, string ...$keys): array
    {
        return array_map(static fn (string $key) => $row[$key], $keys);
    }

    public function test_month_report_adds_weeks_and_grades_to_the_same_totals(): void
    {
        $report = $this->getJson('/api/admin/reports/monthly')->assertOk()
            ->assertJsonPath('period', ['from' => '2026-11-01', 'to' => '2026-11-30', 'previous' => '2026-10-01', 'next' => null, 'is_current' => true])
            ->assertJsonPath('snapshot', null)->json('report');

        $this->assertSame(['monthly', false, 15], [$report['type'], $report['complete'], count($report['school_days'])]);
        $this->assertEquals(
            [3, 7, 45, 6, 15, 4, 73.3, 1, 2, 1, 1],
            $this->pick($report['totals'], 'classes', 'students', 'class_days_expected', 'class_days_surveyed', 'marked', 'absent', 'attendance_pct', 'sick', 'unexcused', 'pending', 'late'),
        );

        $this->assertEquals([
            ['2026-11-02', '2026-11-06', 5, 15, 2, 5, 1, 80.0],
            ['2026-11-09', '2026-11-13', 5, 15, 2, 5, 2, 60.0],
            ['2026-11-16', '2026-11-20', 5, 15, 2, 5, 1, 80.0],
        ], array_map(fn (array $w): array => $this->pick($w, 'from', 'to', 'school_days', 'class_days_expected', 'class_days_surveyed', 'marked', 'absent', 'attendance_pct'), $report['weeks']));

        $this->assertEquals([
            [5, 2, 5, 30, 4, 11, 3, 72.7, 2, 1],
            [7, 1, 2, 15, 2, 4, 1, 75.0, 0, 0],
        ], array_map(fn (array $g): array => $this->pick($g, 'grade', 'classes', 'students', 'class_days_expected', 'class_days_surveyed', 'marked', 'absent', 'attendance_pct', 'unexcused', 'late'), $report['grades']));

        $this->assertSame([['Әлібеков Бірінші', 2, 0, ['2026-11-03', '2026-11-10']], ['Жасандиев Жетінші', 0, 1, ['2026-11-17']]],
            array_map(fn (array $s): array => $this->pick($s, 'name', 'unexcused', 'pending', 'dates'), $report['students']));
    }

    public function test_weeks_are_cut_at_the_month_border(): void
    {
        $report = $this->getJson('/api/admin/reports/monthly?date=2026-10-15')->assertOk()
            ->assertJsonPath('period.from', '2026-10-01')->assertJsonPath('period.next', '2026-11-01')->assertJsonPath('period.is_current', false)
            ->json('report');

        $this->assertTrue($report['complete']);
        // 1 қазан — бейсенбі: бірінші «апта» екі күннен тұрады; соңғы апта 26–30 қазан.
        $this->assertSame(['2026-10-01', '2026-10-02', 2], $this->pick($report['weeks'][0], 'from', 'to', 'school_days'));
        $this->assertEquals(['2026-10-26', '2026-10-30', 1, 3, 1, 66.7], $this->pick(end($report['weeks']), 'from', 'to', 'class_days_surveyed', 'marked', 'pending', 'attendance_pct'));
        $this->assertSame(1, $report['totals']['absent']);
    }

    public function test_month_is_frozen_after_its_last_school_day(): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 11, 30, 18, 39));
        Artisan::call('qatysu:freeze-reports');
        // Қараша әлі аяқталған жоқ; қазанның суреті жоқ болғандықтан ол жасалады.
        $this->assertSame(['2026-10-01'], Report::query()->where('type', 'monthly')->pluck('period_start')->map->toDateString()->all());

        $this->travelTo(CarbonImmutable::create(2026, 11, 30, 18, 41));
        Artisan::call('qatysu:freeze-reports');
        Artisan::call('qatysu:freeze-reports');
        $this->assertSame(['2026-10-01', '2026-11-01'], Report::query()->where('type', 'monthly')->orderBy('period_start')->pluck('period_start')->map->toDateString()->all());

        $this->travelTo(CarbonImmutable::create(2026, 12, 1, 9, 0));
        $this->patchJson("/api/homeroom/classes/{$this->c->id}/days/2026-11-17/students/{$this->ids['Жасандиев']}", ['absence_reason' => 'sick'])->assertOk();
        $this->getJson('/api/admin/reports/monthly?date=2026-11-30')->assertOk()
            ->assertJsonPath('snapshot.changed_since', 1)->assertJsonPath('report.totals.pending', 0)->assertJsonPath('report.totals.sick', 2);
        $this->getJson('/api/admin/reports/monthly?date=2026-11-30&version=snapshot')->assertOk()
            ->assertJsonPath('report.totals.pending', 1)->assertJsonPath('report.grades.1.pending', 1);
    }

    public function test_monthly_excel_has_week_and_grade_sheets(): void
    {
        $response = $this->get('/api/admin/reports/monthly/export')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="qatysu-ai-2026-11.xlsx"');
        $book = $this->open($response->getContent());

        $this->assertSame(['Жиынтық', 'Апталар', 'Параллельдер', 'Сыныптар', 'Дерек жоқ күндер', 'Оқушылар', 'Кешігу мен кету'], $book->getSheetNames());
        $summary = $book->getSheetByName('Жиынтық')->toArray();
        $this->assertSame(['Айлық қатысу есебі', 'ай әлі аяқталған жоқ'], [$summary[0][0], $summary[2][1]]);
        $this->assertEquals(['09.11 – 13.11', 5, '2 / 15', 60, 2, 1, 0, 1, 0, 0, 0], $book->getSheetByName('Апталар')->toArray()[2]);
        $this->assertEquals([5, 2, 5, '4 / 30', 72.7, 3, 1, 0, 2, 0, 1, 0], $book->getSheetByName('Параллельдер')->toArray()[1]);

        $this->admin->update(['locale' => 'zh']);
        $anonymous = $this->open($this->get('/api/admin/reports/monthly/export?names=0')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="qatysu-ai-2026-11-jiyntyq.xlsx"')->getContent());
        $this->assertSame(['汇总', '各周', '各年级', '班级', '无数据的日期'], $anonymous->getSheetNames());
        $text = '';
        foreach ($anonymous->getAllSheets() as $sheet) {
            $text .= json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE);
        }
        $this->assertStringNotContainsString('Әлібеков', $text);
        $this->assertStringNotContainsString('report.', $text);
    }

    public function test_homeroom_month_lists_every_student_of_the_class(): void
    {
        $json = $this->getJson("/api/homeroom/classes/{$this->a->id}/month")->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('class', ['id' => $this->a->id, 'name' => '5А'])
            ->assertJsonPath('month', ['from' => '2026-11-01', 'to' => '2026-11-30', 'previous' => '2026-10-01', 'next' => null, 'is_current' => true])
            ->json();

        $this->assertSame([false, 15, 3, 12], [$json['complete'], $json['days_expected'], $json['days_surveyed'], count($json['missing_dates'])]);
        $this->assertEquals([9, 3, 66.7, 1, 2, 1], $this->pick($json['totals'], 'marked', 'absent', 'attendance_pct', 'sick', 'unexcused', 'late'));
        $this->assertEquals([
            ['Әлібеков Бірінші', true, 3, 2, 0, 2, 0, 33.3, ['2026-11-03', '2026-11-10']],
            ['Сынақов Екінші', true, 3, 1, 1, 0, 0, 66.7, ['2026-11-10']],
            ['Үлгіев Үшінші', true, 3, 0, 0, 0, 1, 100.0, []],
        ], array_map(fn (array $s): array => $this->pick($s, 'name', 'in_class_now', 'marked', 'absent', 'sick', 'unexcused', 'late', 'attendance_pct', 'dates'), $json['students']));
    }

    public function test_transferred_student_is_counted_only_for_days_in_that_class(): void
    {
        app(EnrollmentService::class)->transfer(Student::query()->findOrFail($this->ids['Сынақов']), $this->b, CarbonImmutable::parse('2026-11-18'));

        $old = collect($this->getJson("/api/homeroom/classes/{$this->a->id}/month")->assertOk()->json('students'))->keyBy('name');
        // Ескі сыныбындағы үш күні сол сыныптың қорытындысында қалады.
        $this->assertEquals([false, 3, 1], $this->pick($old['Сынақов Екінші'], 'in_class_now', 'marked', 'sick'));

        // Жаңа сыныбында ауысқаннан кейін әлі аралау болған жоқ: белгіленген күні жоқ, пайызы «дерек жоқ».
        $new = collect($this->getJson("/api/homeroom/classes/{$this->b->id}/month")->assertOk()->json('students'))->keyBy('name');
        $this->assertSame([true, 0, null], $this->pick($new['Сынақов Екінші'], 'in_class_now', 'marked', 'attendance_pct'));

        // Қазанда ол 5Ә-да болған жоқ.
        $october = array_column($this->getJson("/api/homeroom/classes/{$this->b->id}/month?date=2026-10-20")->assertOk()->json('students'), 'name');
        $this->assertSame(['Ойданов Төртінші', 'Тексеров Бесінші'], $october);
    }

    public function test_access_rules(): void
    {
        $this->getJson('/api/admin/reports/monthly?date=2026-12-01')->assertStatus(422)->assertJsonPath('code', 'date_in_future');
        $this->getJson('/api/admin/reports/yearly')->assertStatus(404);
        $this->getJson("/api/homeroom/classes/{$this->a->id}/month?date=2026-12-05")->assertStatus(422)->assertJsonPath('code', 'date_in_future');
        $this->getJson("/api/homeroom/classes/{$this->a->id}/month?date=qazan")->assertStatus(422)->assertJsonPath('code', 'invalid_date');

        // Жетекші тек өз сыныбын көреді; завуч есебі оған жабық.
        $this->actingAs($this->homeroom);
        $this->getJson("/api/homeroom/classes/{$this->a->id}/month")->assertOk();
        $this->getJson("/api/homeroom/classes/{$this->b->id}/month")->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->getJson('/api/admin/reports/monthly')->assertStatus(403);
        $this->getJson('/api/admin/reports/monthly/export')->assertStatus(403);

        $this->actingAs(User::factory()->create());
        $this->getJson("/api/homeroom/classes/{$this->a->id}/month")->assertStatus(403);
    }

    private function open(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'qatysu-test').'.xlsx';
        file_put_contents($path, $content);
        try {
            return IOFactory::load($path);
        } finally {
            @unlink($path);
        }
    }
}
