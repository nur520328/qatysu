<?php

namespace Tests\Feature\Admin;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class StudentImportTest extends AdminTestCase
{
    private const HEADER = ['№', 'Тегі', 'Аты', 'Әкесінің аты (міндетті емес)', 'Оқу тілі', 'Сынып'];

    /** Мектеп файлының пішімі: әр сынып бөлек парақта. */
    private function schoolFile(): UploadedFile
    {
        return $this->xlsx([
            '7В' => [
                self::HEADER,
                [1, 'Тоқтаров', 'Әділ', 'Маратұлы', 'қазақ', '7В'],
                [2, '  Абаев ', 'Алмас', null, 'қазақ', '7В'],
                [3, 'Қасымова', 'Аружан', 'Ерланқызы', 'қазақ', '7В'],
            ],
            '7Г' => [
                ['7Г сыныбының тізімі'],
                [],
                self::HEADER,
                [1, 'Өтегенов', 'Бауыржан', null, 'қазақ', '7Г'],
                [2, null, null, null, null, null],
                [3, 'Ысқақов', 'Рамазан', null, 'қазақ', '7Г'],
            ],
            'Бос парақ' => [],
        ]);
    }

    public function test_preview_changes_nothing_and_commit_imports_exactly_what_was_previewed(): void
    {
        $year = $this->makeYear();
        $this->makeClass($year, '7В');
        $this->makeClass($year, '7Г');

        $preview = $this->post('/api/admin/import/students', ['file' => $this->schoolFile()], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('imported', null)
            ->assertJsonPath('summary', ['new' => 5, 'exists' => 0, 'other_class' => 0, 'errors' => 0]);
        $this->assertSame(0, Student::query()->count());
        $this->assertSame(['7В', '7Г'], array_column($preview->json('sheets'), 'name'));

        $this->post('/api/admin/import/students', ['file' => $this->schoolFile(), 'digest' => $preview->json('digest')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 5);

        $this->assertSame(5, Student::query()->count());
        $class = SchoolClass::query()->where('letter', 'В')->firstOrFail();
        $names = array_column($this->getJson("/api/admin/classes/{$class->id}/students")->json('students'), 'last_name');
        $this->assertSame(['Абаев', 'Қасымова', 'Тоқтаров'], $names);
        $this->assertNull(Student::query()->where('last_name', 'Абаев')->firstOrFail()->patronymic);
    }

    public function test_reupload_adds_nobody_twice(): void
    {
        $year = $this->makeYear();
        $this->makeClass($year, '7В');
        $this->makeClass($year, '7Г');
        $first = $this->post('/api/admin/import/students', ['file' => $this->schoolFile()], ['Accept' => 'application/json']);
        $this->post('/api/admin/import/students', ['file' => $this->schoolFile(), 'digest' => $first->json('digest')], ['Accept' => 'application/json'])->assertOk();

        $again = $this->post('/api/admin/import/students', ['file' => $this->schoolFile()], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('summary', ['new' => 0, 'exists' => 5, 'other_class' => 0, 'errors' => 0]);
        $this->post('/api/admin/import/students', ['file' => $this->schoolFile(), 'digest' => $again->json('digest')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 0);

        $this->assertSame(5, Student::query()->count());
    }

    public function test_reports_each_bad_row_with_sheet_row_and_reason(): void
    {
        $year = $this->makeYear();
        $this->makeClass($year, '7В');
        $this->makeClass($year, '7Д')->forceFill(['is_active' => false])->save();
        $file = $this->xlsx(['7В' => [
            self::HEADER,
            [1, 'Тоқтаров', 'Әділ', null, null, '7В'],
            [2, null, 'Аты ғана', null, null, '7В'],
            [3, 'Тегі ғана', null, null, null, '7В'],
            [4, 'Литерасыз', 'Оқушы', null, null, '7'],
            [5, 'Бос орынмен', 'Оқушы', null, null, '7 В'],
            [6, 'Жоқ сынып', 'Оқушы', null, null, '9Ж'],
            [7, 'Жабық сынып', 'Оқушы', null, null, '7Д'],
            [8, 'тоқтаров', 'ӘДІЛ', null, null, '7В'],
            [9, str_repeat('Ұ', 61), 'Оқушы', null, null, '7В'],
        ]]);

        $response = $this->post('/api/admin/import/students', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('summary', ['new' => 1, 'exists' => 0, 'other_class' => 0, 'errors' => 8]);

        $codes = collect($response->json('problems'))->pluck('code', 'row')->all();
        $this->assertSame([
            3 => 'missing_last_name', 4 => 'missing_first_name', 5 => 'invalid_class', 6 => 'invalid_class',
            7 => 'class_not_found', 8 => 'class_closed', 9 => 'duplicate_in_file', 10 => 'name_too_long',
        ], $codes);
        $this->assertSame('7В', $response->json('problems.0.sheet'));
    }

    public function test_student_already_in_another_class_is_shown_not_moved(): void
    {
        $year = $this->makeYear();
        $v = $this->makeClass($year, '7В');
        $this->makeClass($year, '7Г');
        $student = $this->enrol($v, 'Тоқтаров', 'Әділ', 'Маратұлы');
        $file = $this->xlsx(['7Г' => [self::HEADER, [1, 'Тоқтаров', 'Әділ', 'Маратұлы', null, '7Г']]]);

        $preview = $this->post('/api/admin/import/students', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('summary', ['new' => 0, 'exists' => 0, 'other_class' => 1, 'errors' => 0])
            ->assertJsonPath('other_class.0.current_class', '7В')
            ->assertJsonPath('other_class.0.class', '7Г');
        $this->post('/api/admin/import/students', ['file' => $file, 'digest' => $preview->json('digest')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('imported', 0);

        $this->assertSame(1, Student::query()->count());
        $this->assertSame($v->id, $student->enrollments()->firstOrFail()->class_id);
    }

    public function test_sheet_without_recognisable_header_is_reported_and_others_still_work(): void
    {
        $year = $this->makeYear();
        $this->makeClass($year, '7В');
        $file = $this->xlsx([
            'Түсініксіз' => [['Бірдеңе', 'Тағы бірдеңе'], ['a', 'b']],
            'Орысша' => [['№', 'Фамилия', 'Имя', 'Отчество', 'Язык обучения', 'Класс'], [1, 'Иванов', 'Пётр', null, null, '7В']],
        ]);

        $this->post('/api/admin/import/students', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('sheets.0.error', 'header_not_found')
            ->assertJsonPath('sheets.1.new', 1)
            ->assertJsonPath('summary.new', 1);
    }

    public function test_commit_with_a_different_file_is_refused(): void
    {
        $year = $this->makeYear();
        $this->makeClass($year, '7В');
        $this->makeClass($year, '7Г');
        $digest = $this->post('/api/admin/import/students', ['file' => $this->schoolFile()], ['Accept' => 'application/json'])->json('digest');
        $other = $this->xlsx(['7В' => [self::HEADER, [1, 'Басқа', 'Файл', null, null, '7В']]]);

        $this->post('/api/admin/import/students', ['file' => $other, 'digest' => $digest], ['Accept' => 'application/json'])
            ->assertStatus(409)->assertExactJson(['code' => 'file_changed']);
        $this->assertSame(0, Student::query()->count());
    }

    public function test_rejects_non_xlsx_broken_and_missing_files(): void
    {
        $this->makeYear();
        $csv = UploadedFile::fake()->createWithContent('tizim.csv', "Тегі,Аты,Сынып\n");
        $broken = UploadedFile::fake()->createWithContent('tizim.xlsx', 'бұл excel емес');

        $this->post('/api/admin/import/students', ['file' => $csv], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertExactJson(['code' => 'file_not_xlsx']);
        $this->post('/api/admin/import/students', ['file' => $broken], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertExactJson(['code' => 'file_unreadable']);
        $this->postJson('/api/admin/import/students', [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_teacher_cannot_import(): void
    {
        $this->makeYear();
        $this->actingAs(User::factory()->create());

        $this->post('/api/admin/import/students', ['file' => $this->schoolFile()], ['Accept' => 'application/json'])->assertStatus(403);
    }
}
