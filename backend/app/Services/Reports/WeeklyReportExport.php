<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Апталық есептің Excel файлы. Сұраған сәтте, сұраған адамның тілінде жасалады; дискіге сақталмайды
 * (docs/кезең-8-жоспар.md, 8 және 9-шешім). Аты-жөнсіз есепте «Оқушылар» мен «Кешігу мен кету» парақтары болмайды.
 */
class WeeklyReportExport
{
    private string $locale = 'kk';

    /**
     * @param  array<string, mixed>  $report  `WeeklyReportService::compute()` нәтижесі не бекітілген сурет
     * @return string файлдың мазмұны
     */
    public function build(array $report, string $locale, bool $snapshot = false): string
    {
        $this->locale = $locale;
        $book = new Spreadsheet;
        $book->getDefaultStyle()->getFont()->setName('Arial')->setSize(10);
        $book->removeSheetByIndex(0);

        $this->summary($book->createSheet(), $report, $snapshot);
        $this->classes($book->createSheet(), $report);
        $this->missing($book->createSheet(), $report);
        if (! ($report['anonymous'] ?? false)) {
            $this->students($book->createSheet(), $report);
            $this->events($book->createSheet(), $report);
        }
        $book->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'qatysu-report');
        try {
            (new Xlsx($book))->save($path);

            return (string) file_get_contents($path);
        } finally {
            $book->disconnectWorksheets();
            @unlink($path);
        }
    }

    /** @param  array<string, mixed>  $report */
    private function summary(Worksheet $sheet, array $report, bool $snapshot): void
    {
        $sheet->setTitle($this->t('sheet_summary'));
        $totals = $report['totals'];
        $state = $snapshot ? 'state_snapshot' : ($report['complete'] ? 'state_live' : 'state_incomplete');
        $rows = [
            [$this->t('title_weekly')],
            [$this->t('period'), $this->date($report['from']).' – '.$this->date($report['to'])],
            [$this->t('state'), $this->t($state)],
            [],
            [$this->t('metric'), $this->t('value')],
            [$this->t('classes'), $totals['classes']],
            [$this->t('students'), $totals['students']],
            [$this->t('class_days'), $this->t('of', ['done' => $totals['class_days_surveyed'], 'total' => $totals['class_days_expected']])],
            [$this->t('attendance_pct'), $this->pct($totals['attendance_pct'])],
            [$this->t('absent'), $totals['absent']],
            ['   '.$this->t('sick'), $totals['sick']],
            ['   '.$this->t('excused'), $totals['excused']],
            ['   '.$this->t('unexcused'), $totals['unexcused']],
            ['   '.$this->t('pending'), $totals['pending']],
            [$this->t('late'), $totals['late']],
            [$this->t('left'), $totals['left']],
            ['   '.$this->t('left_permitted'), $totals['left_permitted']],
            ['   '.$this->t('left_unpermitted'), $totals['left_unpermitted']],
            ['   '.$this->t('left_pending'), $totals['left_pending']],
            [],
            [$this->t('by_day')],
            [$this->t('date'), $this->t('class_days'), $this->t('attendance_pct'), $this->t('absent'), $this->t('late'), $this->t('left')],
        ];
        foreach ($report['days'] as $day) {
            $rows[] = [
                $this->date($day['date']),
                $this->t('of', ['done' => $day['class_days_surveyed'], 'total' => $day['class_days_expected']]),
                $this->pct($day['attendance_pct']), $day['absent'], $day['late'], $day['left'],
            ];
        }
        $rows[] = [];
        $rows[] = [$this->t('pct_note')];
        if ($report['anonymous'] ?? false) {
            $rows[] = [$this->t('anonymous')];
        }
        $this->write($sheet, $rows, headerRows: [5, 22], titleRows: [1, 21], freeze: false);
        $sheet->getColumnDimension('A')->setAutoSize(false)->setWidth(44);
    }

    /** @param  array<string, mixed>  $report */
    private function classes(Worksheet $sheet, array $report): void
    {
        $sheet->setTitle($this->t('sheet_classes'));
        $rows = [[
            $this->t('class'), $this->t('shift'), $this->t('homeroom'), $this->t('students'), $this->t('days_surveyed'), $this->t('days_expected'),
            $this->t('attendance_pct'), $this->t('absent'), $this->t('sick'), $this->t('excused'), $this->t('unexcused'), $this->t('pending'),
            $this->t('late'), $this->t('left'),
        ]];
        foreach ($report['classes'] as $class) {
            $rows[] = [
                $class['name'], $this->t('shift_'.$class['shift']), $class['homeroom'] ?? '', $class['students'], $class['days_surveyed'], $class['days_expected'],
                $this->pct($class['attendance_pct']), $class['absent'], $class['sick'], $class['excused'], $class['unexcused'], $class['pending'],
                $class['late'], $class['left'],
            ];
        }
        $this->write($sheet, $rows);
    }

    /** @param  array<string, mixed>  $report */
    private function missing(Worksheet $sheet, array $report): void
    {
        $sheet->setTitle($this->t('sheet_missing'));
        $rows = [[$this->t('class'), $this->t('homeroom'), $this->t('missing_dates')]];
        foreach ($report['classes'] as $class) {
            if ($class['missing_dates'] !== []) {
                $rows[] = [$class['name'], $class['homeroom'] ?? '', implode(', ', array_map($this->shortDate(...), $class['missing_dates']))];
            }
        }
        if (count($rows) === 1) {
            $rows[] = [$this->t('none_missing')];
        }
        $this->write($sheet, $rows);
    }

    /** @param  array<string, mixed>  $report */
    private function students(Worksheet $sheet, array $report): void
    {
        $sheet->setTitle($this->t('sheet_students'));
        $rows = [[$this->t('student'), $this->t('class'), $this->t('unexcused_days'), $this->t('pending_days'), $this->t('dates')]];
        foreach ($report['students'] as $student) {
            $rows[] = [$student['name'], $student['class'], $student['unexcused'], $student['pending'], implode(', ', array_map($this->shortDate(...), $student['dates']))];
        }
        if ($report['students'] === []) {
            $rows[] = [$this->t('none_students')];
        } elseif ($report['students_total'] > count($report['students'])) {
            $rows[] = [$this->t('students_more', ['shown' => count($report['students']), 'total' => $report['students_total']])];
        }
        $this->write($sheet, $rows);
    }

    /** @param  array<string, mixed>  $report */
    private function events(Worksheet $sheet, array $report): void
    {
        $sheet->setTitle($this->t('sheet_events'));
        $rows = [[$this->t('date'), $this->t('class'), $this->t('student'), $this->t('arrived_lesson'), $this->t('left_lesson'), $this->t('permission')]];
        foreach ($report['events'] as $event) {
            $rows[] = [
                $this->date($event['date']), $event['class'], $event['name'], $event['arrived_lesson'] ?? '', $event['left_lesson'] ?? '',
                $event['left_lesson'] === null ? '' : $this->t('left_'.($event['leave_permission'] ?? 'pending')),
            ];
        }
        $rows[] = $report['events'] === [] ? [$this->t('none_events')] : [];
        $rows[] = [$this->t('events_note')];
        $this->write($sheet, $rows);
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<int>  $headerRows  кесте тақырыбы жолдары (1-ден бастап)
     * @param  list<int>  $titleRows
     */
    private function write(Worksheet $sheet, array $rows, array $headerRows = [1], array $titleRows = [], bool $freeze = true): void
    {
        foreach ($rows as $index => $cells) {
            foreach (array_values($cells) as $column => $value) {
                // Мәтін формула болып оқылмауы үшін («=» не «+» таңбасынан басталатын аты-жөн, түсініктеме) анық түрімен жазылады.
                $cell = $sheet->getCell([$column + 1, $index + 1]);
                is_int($value) || is_float($value) ? $cell->setValue($value) : $cell->setValueExplicit((string) $value);
            }
        }
        $lastColumn = $sheet->getHighestColumn();
        foreach ($headerRows as $row) {
            $style = $sheet->getStyle("A{$row}:{$lastColumn}{$row}");
            $style->getFont()->setBold(true);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DCE6F5');
            $style->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        }
        foreach ($titleRows as $row) {
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        }
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
        if ($freeze) {
            $sheet->freezePane('A2');
        }
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    }

    /** @param  array<string, int|string>  $replace */
    private function t(string $key, array $replace = []): string
    {
        return __('report.'.$key, $replace, $this->locale);
    }

    private function date(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d.m.Y');
    }

    private function shortDate(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d.m');
    }

    private function pct(?float $value): float|string
    {
        return $value ?? $this->t('no_data');
    }
}
