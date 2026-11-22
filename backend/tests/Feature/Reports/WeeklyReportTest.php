<?php

namespace Tests\Feature\Reports;

use App\Enums\Shift;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\Reports\ReportSnapshotService;
use App\Services\Reports\WeeklyReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\Duty\DutyTestCase;

/** Апталық есеп. Апта: 2026-11-16 (дс) – 2026-11-22 (жс); «қазір» жұма 20 қараша, 08:10. Дерек жасанды. */
class WeeklyReportTest extends DutyTestCase
{
    private SchoolClass $a;

    private SchoolClass $b;

    /** @var array<string, int> */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $homeroom = User::factory()->create(['full_name' => 'Сынақ Жетекші']);
        $this->a = $this->classWith('5А', ['Әлібеков Бірінші', 'Сынақов Екінші', 'Үлгіев Үшінші', 'Жасандиев Төртінші']);
        $this->a->update(['homeroom_user_id' => $homeroom->id]);
        $this->b = $this->classWith('6Ә', ['Ойданов Алтыншы', 'Тексеров Жетінші'], Shift::Afternoon);
        $this->ids = Student::query()->pluck('id', 'last_name')->all();

        // Дүйсенбі: 5А-да Әлібеков ауырды, Сынақов себепсіз; 6Ә толық.
        $this->mark($this->a, '2026-11-16', ['Әлібеков' => ['round_status' => 'absent', 'absence_reason' => 'sick'], 'Сынақов' => ['round_status' => 'absent', 'absence_reason' => 'unexcused']]);
        $this->mark($this->b, '2026-11-16');
        // Сейсенбі: 5А-да Сынақов жоқ (себебі анықталмаған), Үлгіев 2-сабақтан келді; 6Ә аралынбады, бірақ мұғалім Ойдановтың кеткенін тіркеді.
        $this->mark($this->a, '2026-11-17', ['Сынақов' => ['round_status' => 'absent'], 'Үлгіев' => ['round_status' => 'absent', 'arrived_lesson' => 2]]);
        $this->mark($this->b, '2026-11-17', ['Ойданов' => ['round_status_source' => 'staff', 'left_lesson' => 3, 'leave_permission' => 'permitted']], duty: false, only: ['Ойданов']);
        // Сәрсенбі: 5А толық, Жасандиев 5-сабақтан кетті (рұқсаты анықталмаған); 6Ә-да Тексеров себепсіз.
        $this->mark($this->a, '2026-11-18', ['Жасандиев' => ['left_lesson' => 5]]);
        $this->mark($this->b, '2026-11-18', ['Тексеров' => ['round_status' => 'absent', 'absence_reason' => 'unexcused']]);
        // Бейсенбі мен жұма: ешбір сынып аралынған жоқ.
        $this->actingAs($this->admin);
    }

    /**
     * @param  array<string, array<string, mixed>>  $overrides  тегі => өзгеше өрістер
     * @param  list<string>|null  $only  тек осы оқушыларға жазба (мұғалім ашқан күн)
     */
    private function mark(SchoolClass $class, string $date, array $overrides = [], bool $duty = true, ?array $only = null): int
    {
        $roundId = DB::table('attendance_rounds')->insertGetId([
            'class_id' => $class->id, 'date' => $date, 'shift' => $class->shift->value, 'client_uuid' => (string) Str::uuid(),
            'revision' => 1, 'received_late' => false, 'duty_done' => $duty, 'created_at' => $date.' 08:30:00', 'updated_at' => $date.' 08:30:00',
        ]);
        $names = DB::table('student_enrollments')->join('students', 'students.id', '=', 'student_enrollments.student_id')
            ->where('class_id', $class->id)->pluck('students.id', 'students.last_name');
        foreach ($names as $name => $studentId) {
            if ($only !== null && ! in_array($name, $only, true)) {
                continue;
            }
            DB::table('attendance_records')->insert(($overrides[$name] ?? []) + [
                'round_id' => $roundId, 'student_id' => $studentId, 'round_status' => 'present', 'round_status_source' => 'duty',
                'created_at' => $date.' 08:30:00', 'updated_at' => $date.' 08:30:00',
            ]);
        }

        return $roundId;
    }

    /** @return array<string, mixed> */
    private function report(string $query = ''): array
    {
        return $this->getJson('/api/admin/reports/weekly'.$query)->assertOk()->json('report');
    }

    public function test_week_totals_use_only_surveyed_class_days_for_the_percentage(): void
    {
        $json = $this->getJson('/api/admin/reports/weekly')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('week', ['from' => '2026-11-16', 'to' => '2026-11-22', 'previous' => '2026-11-09', 'next' => null, 'is_current' => true])
            ->assertJsonPath('version', 'live')->assertJsonPath('snapshot', null)->json('report');

        $this->assertSame(['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'], $json['school_days']);
        $this->assertFalse($json['complete']);
        $this->assertEquals([
            'marked' => 16, 'absent_marked' => 4, 'absent' => 4, 'sick' => 1, 'excused' => 0, 'unexcused' => 2, 'pending' => 1,
            'late' => 1, 'left' => 2, 'left_permitted' => 1, 'left_unpermitted' => 0, 'left_pending' => 1,
            'classes' => 2, 'students' => 6, 'class_days_expected' => 10, 'class_days_surveyed' => 5, 'attendance_pct' => 75.0,
        ], $json['totals']);
    }

    public function test_class_rows_list_missing_days_instead_of_counting_them_as_full_attendance(): void
    {
        [$a, $b] = $this->report()['classes'];

        $this->assertEquals(
            ['5А', 'morning', 'Сынақ Жетекші', 4, 5, 3, ['2026-11-19', '2026-11-20'], 12, 3, 75.0, 1, 0, 1, 1, 1, 1],
            [$a['name'], $a['shift'], $a['homeroom'], $a['students'], $a['days_expected'], $a['days_surveyed'], $a['missing_dates'], $a['marked'], $a['absent'], $a['attendance_pct'], $a['sick'], $a['excused'], $a['unexcused'], $a['pending'], $a['late'], $a['left']],
        );
        // 6Ә: сейсенбі аралынбады (мұғалімнің жазбасы пайызға кірмейді, бірақ кету саналады).
        $this->assertEquals(
            ['6Ә', 'afternoon', null, 2, 2, ['2026-11-17', '2026-11-19', '2026-11-20'], 4, 75.0, 1, 1, 1],
            [$b['name'], $b['shift'], $b['homeroom'], $b['students'], $b['days_surveyed'], $b['missing_dates'], $b['marked'], $b['attendance_pct'], $b['unexcused'], $b['left'], $b['left_permitted']],
        );
    }

    public function test_days_students_and_events_are_listed(): void
    {
        $report = $this->report();

        $days = array_column($report['days'], null, 'date');
        $this->assertEquals([2, 2, 6, 2, 66.7], [$days['2026-11-16']['class_days_expected'], $days['2026-11-16']['class_days_surveyed'], $days['2026-11-16']['marked'], $days['2026-11-16']['absent'], $days['2026-11-16']['attendance_pct']]);
        $this->assertEquals([1, 4, 1, 1, 1, 75.0], [$days['2026-11-17']['class_days_surveyed'], $days['2026-11-17']['marked'], $days['2026-11-17']['absent'], $days['2026-11-17']['late'], $days['2026-11-17']['left'], $days['2026-11-17']['attendance_pct']]);
        $this->assertSame([0, 0, null], [$days['2026-11-19']['class_days_surveyed'], $days['2026-11-19']['marked'], $days['2026-11-19']['attendance_pct']]);

        // Себепсіз күні тең: анықталмағаны бар Сынақов жоғарыда.
        $this->assertSame(2, $report['students_total']);
        $this->assertSame([
            ['Сынақов Екінші', '5А', 1, 1, ['2026-11-16', '2026-11-17']],
            ['Тексеров Жетінші', '6Ә', 1, 0, ['2026-11-18']],
        ], array_map(static fn (array $s): array => [$s['name'], $s['class'], $s['unexcused'], $s['pending'], $s['dates']], $report['students']));

        $this->assertSame([
            ['2026-11-17', '5А', 'Үлгіев Үшінші', 2, null, null],
            ['2026-11-17', '6Ә', 'Ойданов Алтыншы', null, 3, 'permitted'],
            ['2026-11-18', '5А', 'Жасандиев Төртінші', null, 5, null],
        ], array_map(static fn (array $e): array => [$e['date'], $e['class'], $e['name'], $e['arrived_lesson'], $e['left_lesson'], $e['leave_permission']], $report['events']));
    }

    public function test_future_days_and_holidays_are_not_expected(): void
    {
        // Сәрсенбі 08:10: бейсенбі мен жұма әлі болған жоқ; сейсенбі кейін мереке деп белгіленді.
        $this->travelTo(CarbonImmutable::create(2026, 11, 18, 8, 10));
        app(CalendarService::class)->setRange($this->year, CarbonImmutable::parse('2026-11-17'), CarbonImmutable::parse('2026-11-17'), false, 'мереке');

        $report = $this->report();
        $this->assertSame(['2026-11-16', '2026-11-18'], $report['school_days']);
        $this->assertSame([4, 4], [$report['totals']['class_days_expected'], $report['totals']['class_days_surveyed']]);
        $this->assertSame([[], []], array_column($report['classes'], 'missing_dates'));
        // Мереке күнгі жазбалар жойылмайды: оқиғалар мен себептер саналады, тек «күтілетін күн» емес.
        $this->assertSame([1, 2], [$report['totals']['late'], $report['totals']['left']]);
    }

    public function test_week_is_complete_only_after_the_last_school_day_ends(): void
    {
        $service = app(WeeklyReportService::class);
        $monday = CarbonImmutable::parse('2026-11-16');

        $this->assertFalse($service->isComplete($monday));
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 18, 39));
        $this->assertFalse($service->isComplete($monday));
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 18, 41));
        $this->assertTrue($service->isComplete($monday));
        $this->assertTrue($service->isComplete($monday->subWeek()));
        $this->assertFalse($service->isComplete($monday->addWeek()));

        // Жұма каникул болса, апта бейсенбінің кешінде аяқталады.
        app(CalendarService::class)->setRange($this->year, CarbonImmutable::parse('2026-11-20'), CarbonImmutable::parse('2026-11-20'), false, 'каникул');
        $this->travelTo(CarbonImmutable::create(2026, 11, 19, 18, 39));
        $this->assertFalse($service->isComplete($monday));
        $this->travelTo(CarbonImmutable::create(2026, 11, 19, 18, 41));
        $this->assertTrue($service->isComplete($monday));
    }

    public function test_report_is_frozen_once_when_the_week_ends_and_never_recalculated(): void
    {
        $this->assertSame(0, Artisan::call('qatysu:freeze-reports'));
        // Ағымдағы апта әлі аяқталған жоқ; өткен аптада (9–15 қараша) бірде-бір жазба жоқ, сондықтан ол бекітілмейді.
        $this->assertSame(0, Report::query()->count());

        // Өткен аптада жазба болса және суреті жоқ болса, ол кешігіп болса да бекітіледі.
        $this->mark($this->a, '2026-11-13');
        Artisan::call('qatysu:freeze-reports');
        $this->assertSame(['2026-11-09'], Report::query()->pluck('period_start')->map->toDateString()->all());

        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 19, 5));
        Artisan::call('qatysu:freeze-reports');
        Artisan::call('qatysu:freeze-reports');
        $this->assertSame(['2026-11-09', '2026-11-16'], Report::query()->orderBy('period_start')->pluck('period_start')->map->toDateString()->all());
        $snapshot = app(ReportSnapshotService::class)->weekly(CarbonImmutable::parse('2026-11-16'));
        $this->assertTrue($snapshot->data['complete']);
        $this->assertSame(2, $snapshot->data['totals']['unexcused']);

        $this->getJson('/api/admin/reports/weekly')->assertOk()->assertJsonPath('snapshot.changed_since', 0)
            ->assertJsonPath('snapshot.generated_at', '2026-11-20T19:05:00+05:00');

        // Дүйсенбіде жетекші Тексеровтің себебін «себепті» деп түзетті: бекітілген есеп өзгермейді, қазіргі күй өзгереді.
        $this->travelTo(CarbonImmutable::create(2026, 11, 23, 9, 0));
        $this->patchJson("/api/homeroom/classes/{$this->b->id}/days/2026-11-18/students/{$this->ids['Тексеров']}", ['absence_reason' => 'excused'])->assertOk();

        $this->getJson('/api/admin/reports/weekly?week=2026-11-18')->assertOk()
            ->assertJsonPath('week.next', '2026-11-23')->assertJsonPath('week.is_current', false)
            ->assertJsonPath('version', 'live')->assertJsonPath('snapshot.changed_since', 1)
            ->assertJsonPath('report.totals.unexcused', 1)->assertJsonPath('report.totals.excused', 1);
        $this->getJson('/api/admin/reports/weekly?week=2026-11-16&version=snapshot')->assertOk()
            ->assertJsonPath('version', 'snapshot')->assertJsonPath('report.totals.unexcused', 2)->assertJsonPath('report.totals.excused', 0);
    }

    public function test_week_without_school_days_is_not_frozen(): void
    {
        app(CalendarService::class)->setRange($this->year, CarbonImmutable::parse('2026-11-09'), CarbonImmutable::parse('2026-11-15'), false, 'каникул');

        Artisan::call('qatysu:freeze-reports');
        $this->assertSame(0, Report::query()->count());
    }

    public function test_excel_export_has_all_sheets_and_matches_the_report(): void
    {
        $response = $this->get('/api/admin/reports/weekly/export?week=2026-11-16')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="qatysu-apta-2026-11-16.xlsx"');
        $book = $this->open($response->getContent());

        $this->assertSame(['Жиынтық', 'Сыныптар', 'Дерек жоқ күндер', 'Оқушылар', 'Кешігу мен кету'], $book->getSheetNames());
        $summary = $book->getSheetByName('Жиынтық')->toArray();
        $this->assertSame(['Кезең', '16.11.2026 – 22.11.2026'], array_slice($summary[1], 0, 2));
        $this->assertSame('апта әлі аяқталған жоқ', $summary[2][1]);
        $values = array_column($summary, 1, 0);
        $this->assertEquals(['5 / 10', 75, 4, 2, 1], [$values['Аралынған сынып-күн'], $values['Қатысу, %'], $values['Күні бойы жоқ (оқушы-күн)'], $values['   Себепсіз'], $values['   рұқсаты анықталмаған']]);
        $thursday = $summary[array_search('19.11.2026', array_column($summary, 0), true)];
        $this->assertSame(['0 / 2', 'дерек жоқ'], [$thursday[1], $thursday[2]]);

        $classes = $book->getSheetByName('Сыныптар')->toArray();
        $this->assertEquals(['5А', 'таңғы', 'Сынақ Жетекші', 4, 3, 5, 75, 3, 1, 0, 1, 1, 1, 1], $classes[1]);
        $this->assertSame(['6Ә', null, '17.11, 19.11, 20.11'], $book->getSheetByName('Дерек жоқ күндер')->toArray()[2]);
        $this->assertSame(['Сынақов Екінші', '5А', 1, 1, '16.11, 17.11'], array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $book->getSheetByName('Оқушылар')->toArray()[1]));
        $events = $book->getSheetByName('Кешігу мен кету')->toArray();
        $this->assertEquals(['17.11.2026', '6Ә', 'Ойданов Алтыншы', null, 3, 'рұқсатпен'], $events[2]);
        $this->assertEquals(['18.11.2026', '5А', 'Жасандиев Төртінші', null, 5, 'рұқсаты анықталмаған'], $events[3]);
    }

    public function test_anonymous_export_contains_no_student_names_in_any_language(): void
    {
        foreach (['kk' => 'Сыныптар', 'ru' => 'Классы', 'zh' => '班级'] as $locale => $classesSheet) {
            $this->admin->update(['locale' => $locale]);
            $response = $this->get('/api/admin/reports/weekly/export?week=2026-11-16&names=0')->assertOk()
                ->assertHeader('Content-Disposition', 'attachment; filename="qatysu-apta-2026-11-16-jiyntyq.xlsx"');
            $book = $this->open($response->getContent());

            $this->assertCount(3, $book->getSheetNames());
            $this->assertSame($classesSheet, $book->getSheetNames()[1]);
            $text = '';
            foreach ($book->getAllSheets() as $sheet) {
                $text .= json_encode($sheet->toArray(), JSON_UNESCAPED_UNICODE);
            }
            foreach (['Сынақов', 'Тексеров', 'Үлгіев', 'Ойданов', 'Жасандиев', 'Әлібеков'] as $name) {
                $this->assertStringNotContainsString($name, $text, "{$locale}: {$name}");
            }
            $this->assertStringNotContainsString('report.', $text, "{$locale}: аударылмаған кілт");
        }
    }

    public function test_report_texts_have_the_same_keys_in_three_languages(): void
    {
        $keys = static fn (string $locale): array => array_keys(require base_path("lang/{$locale}/report.php"));

        $this->assertSame($keys('kk'), $keys('ru'));
        $this->assertSame($keys('kk'), $keys('zh'));
    }

    public function test_names_that_look_like_formulas_are_written_as_text(): void
    {
        Student::query()->whereKey($this->ids['Сынақов'])->update(['last_name' => '=1+1']);

        $book = $this->open($this->get('/api/admin/reports/weekly/export?week=2026-11-16')->assertOk()->getContent());
        $cell = $book->getSheetByName('Оқушылар')->getCell('A2');
        $this->assertSame('s', $cell->getDataType());
        $this->assertSame('=1+1 Екінші', $cell->getValue());
    }

    public function test_requests_are_validated_and_limited_to_admin(): void
    {
        $this->getJson('/api/admin/reports/weekly?week=2026-11-23')->assertStatus(422)->assertJsonPath('code', 'date_in_future');
        $this->getJson('/api/admin/reports/weekly?week=2026-02-30')->assertStatus(422)->assertJsonPath('code', 'invalid_date');
        $this->getJson('/api/admin/reports/weekly?version=draft')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->getJson('/api/admin/reports/weekly?version=snapshot')->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->getJson('/api/admin/reports/weekly/export?week=2026-11-23')->assertStatus(422);

        $this->actingAs(User::factory()->create());
        $this->getJson('/api/admin/reports/weekly')->assertStatus(403);
        $this->getJson('/api/admin/reports/weekly/export')->assertStatus(403);
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
