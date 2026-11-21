<?php

namespace App\Services\Import;

use DomainException;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Throwable;

/**
 * Excel файлын (.xlsx) қарапайым мәтін кестесіне айналдырады. Тек мәндер оқылады:
 * формула, пішім, макрос ескерілмейді. Файл серверде сақталмайды.
 */
class SpreadsheetReader
{
    public const MAX_ROWS_PER_SHEET = 2000;

    public const MAX_COLUMNS = 20;

    /**
     * @return list<array{name: string, rows: array<int, list<string>>}> rows кілті — Excel-дегі жол нөмірі (1-ден).
     */
    public function read(string $path): array
    {
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (Throwable) {
            throw new DomainException('file_unreadable');
        }

        $sheets = [];
        foreach ($book->getAllSheets() as $sheet) {
            $lastRow = min($sheet->getHighestDataRow(), self::MAX_ROWS_PER_SHEET);
            if ($sheet->getHighestDataRow() > self::MAX_ROWS_PER_SHEET) {
                throw new DomainException('too_many_rows');
            }
            $rows = [];
            for ($r = 1; $r <= $lastRow; $r++) {
                $cells = [];
                for ($c = 1; $c <= self::MAX_COLUMNS; $c++) {
                    $value = $sheet->getCell([$c, $r])->getValue();
                    $cells[] = self::text($value);
                }
                if (implode('', $cells) !== '') {
                    $rows[$r] = $cells;
                }
            }
            $sheets[] = ['name' => $sheet->getTitle(), 'rows' => $rows];
        }
        $book->disconnectWorksheets();

        return $sheets;
    }

    private static function text(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }
        // Excel бүтін санды 7.0 түрінде беруі мүмкін: «7» деп жазылады.
        if (is_float($value) && floor($value) === $value) {
            $value = (int) $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * Тақырып жолын іздейді: алғашқы 15 жолдың ішінде барлық міндетті баған аталған жол.
     *
     * @param  array<int, list<string>>  $rows
     * @param  array<string, list<string>>  $columns  баған кілті => тақырыптың ықтимал басталулары (кіші әріппен)
     * @param  list<string>  $required
     * @return array{int, array<string, int>}|null [тақырып жолының нөмірі, баған кілті => индекс]
     */
    public static function findHeader(array $rows, array $columns, array $required): ?array
    {
        foreach ($rows as $rowNumber => $cells) {
            if ($rowNumber > 15) {
                break;
            }
            $map = [];
            foreach ($cells as $index => $cell) {
                $label = mb_strtolower($cell);
                foreach ($columns as $key => $prefixes) {
                    if (isset($map[$key])) {
                        continue;
                    }
                    foreach ($prefixes as $prefix) {
                        if ($label !== '' && str_starts_with($label, $prefix)) {
                            $map[$key] = $index;
                            break 2;
                        }
                    }
                }
            }
            if (array_diff($required, array_keys($map)) === []) {
                return [$rowNumber, $map];
            }
        }

        return null;
    }
}
