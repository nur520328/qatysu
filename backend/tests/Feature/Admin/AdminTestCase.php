<?php

namespace Tests\Feature\Admin;

use App\Enums\Shift;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\EnrollmentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Әкімші API тесттерінің ортақ дайындығы. Барлық дерек жасанды. */
abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 2026-11-20 — жұма, оқу күні.
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 10));
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    protected function makeYear(string $name = '2026–2027', string $start = '2026-09-01', string $end = '2027-05-25', bool $current = true): AcademicYear
    {
        $year = AcademicYear::query()->create([
            'name' => $name, 'start_date' => $start, 'end_date' => $end, 'is_current' => $current,
        ]);
        app(CalendarService::class)->generate($year);

        return $year;
    }

    protected function makeClass(AcademicYear $year, string $name, Shift $shift = Shift::Afternoon, ?User $homeroom = null): SchoolClass
    {
        preg_match('/\A(\d+)(.+)\z/u', $name, $m);

        return SchoolClass::query()->create([
            'academic_year_id' => $year->id,
            'grade' => (int) $m[1],
            'letter' => $m[2],
            'shift' => $shift,
            'homeroom_user_id' => $homeroom?->id,
        ]);
    }

    /**
     * Сынақ үшін .xlsx файл жасайды. Кілті — парақ атауы, мәні — жолдар тізімі.
     *
     * @param  array<string, list<list<string|int|null>>>  $sheets
     */
    protected function xlsx(array $sheets, string $name = 'tizim.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet();
            $sheet->setTitle((string) $title);
            $sheet->fromArray($rows, null, 'A1', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'qatysu').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        $this->tempFiles[] = $path;

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    protected function enrol(SchoolClass $class, string $last, string $first, ?string $patronymic = null, string $date = '2026-09-01'): Student
    {
        $student = Student::query()->create(['last_name' => $last, 'first_name' => $first, 'patronymic' => $patronymic]);
        app(EnrollmentService::class)->enroll($student, $class, CarbonImmutable::parse($date));

        return $student;
    }
}
