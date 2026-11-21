<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Дерекқорды таза PHP арқылы сығылған SQL файлына жазады және одан қалпына келтіреді.
 * Хостингте SSH жоқ, ал `mysqldump` сияқты сыртқы бағдарламаларды іске қосу ортақ хостингте
 * жиі тыйым салынған, сондықтан оларға сүйенбейміз.
 *
 * Файл пішімі: кәдімгі SQL (phpMyAdmin де оқи алады). Әр команда жеке «-- ;;» жолымен
 * аяқталады; жол мәндеріндегі жаңа жол таңбалары экрандалады, сондықтан бұл белгі дерек
 * ішінде кездеспейді. Соңғы жол — файлдың толық жазылғанын растайтын қорытынды.
 */
class DatabaseDumper
{
    public const SEPARATOR = '-- ;;';

    private const HEADER = '-- qatysu-backup ';

    private const FOOTER = '-- qatysu-backup-end ';

    /** Құрылымы сақталады, ал мазмұны сақталмайды: уақытша дерек, қалпына келтірудің қажеті жоқ. */
    private const STRUCTURE_ONLY = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'];

    private const ROWS_PER_INSERT = 200;

    /**
     * @return array{tables: int, rows: int}
     */
    public function dump(string $path): array
    {
        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new RuntimeException("Файл ашылмады: {$path}");
        }

        $pdo = DB::connection()->getPdo();
        $meta = ['version' => (string) config('app.version'), 'created_at' => now()->toIso8601String()];
        $tables = 0;
        $rows = 0;

        // Барлық кесте бір сәттегі күйінде оқылады: көшірме кезінде біреу белгі қойса да, файл қайшылықсыз болады.
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->write($gz, self::HEADER.json_encode($meta)."\n");
            $this->statement($gz, 'SET NAMES utf8mb4');
            $this->statement($gz, 'SET FOREIGN_KEY_CHECKS=0');

            foreach ($this->tables() as $table) {
                $quoted = '`'.str_replace('`', '``', $table).'`';
                $create = (array) DB::selectOne("SHOW CREATE TABLE {$quoted}");
                $this->statement($gz, "DROP TABLE IF EXISTS {$quoted}");
                $this->statement($gz, (string) array_values($create)[1]);
                $tables++;

                if (in_array($table, self::STRUCTURE_ONLY, true)) {
                    continue;
                }

                $batch = [];
                foreach (DB::table($table)->cursor() as $row) {
                    $batch[] = '('.implode(',', array_map(fn ($value): string => $this->literal($pdo, $value), (array) $row)).')';
                    $rows++;
                    if (count($batch) === self::ROWS_PER_INSERT) {
                        $this->statement($gz, "INSERT INTO {$quoted} VALUES\n".implode(",\n", $batch));
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    $this->statement($gz, "INSERT INTO {$quoted} VALUES\n".implode(",\n", $batch));
                }
            }

            $this->statement($gz, 'SET FOREIGN_KEY_CHECKS=1');
            $this->write($gz, self::FOOTER.json_encode(['tables' => $tables, 'rows' => $rows])."\n");
        } finally {
            DB::rollBack();
            gzclose($gz);
        }

        return ['tables' => $tables, 'rows' => $rows];
    }

    /**
     * Файлды оқып, толық жазылғанын тексереді. Толық болмаса null.
     *
     * @return array{version: string, created_at: string, tables: int, rows: int}|null
     */
    public function inspect(string $path): ?array
    {
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            return null;
        }
        $first = (string) gzgets($gz);
        $last = '';
        while (($line = gzgets($gz)) !== false) {
            $last = $line;
        }
        gzclose($gz);

        if (! str_starts_with($first, self::HEADER) || ! str_starts_with($last, self::FOOTER)) {
            return null;
        }
        $head = json_decode(substr($first, strlen(self::HEADER)), true);
        $foot = json_decode(substr($last, strlen(self::FOOTER)), true);
        if (! is_array($head) || ! is_array($foot) || ! isset($head['version'], $head['created_at'], $foot['tables'], $foot['rows'])) {
            return null;
        }

        return ['version' => (string) $head['version'], 'created_at' => (string) $head['created_at'], 'tables' => (int) $foot['tables'], 'rows' => (int) $foot['rows']];
    }

    /**
     * Файлдағы күйді қайтарады: ондағы кестелер қайта жасалады, файлда жоқ кестелер өшіріледі.
     * Шақырушы алдымен `inspect` арқылы файлдың толықтығына көз жеткізуі тиіс.
     */
    public function restore(string $path): void
    {
        if ($this->inspect($path) === null) {
            throw new RuntimeException('Сақтық көшірме файлы толық емес не бүлінген.');
        }

        $gz = gzopen($path, 'rb');
        if ($gz === false) {
            throw new RuntimeException("Файл ашылмады: {$path}");
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            // Көшірмеден кейін қосылған кестелер қалмауы тиіс, әйтпесе ескі дерек пен жаңа құрылым араласады.
            foreach ($this->tables() as $table) {
                DB::statement('DROP TABLE IF EXISTS `'.str_replace('`', '``', $table).'`');
            }

            $buffer = '';
            while (($line = gzgets($gz)) !== false) {
                if (rtrim($line, "\r\n") === self::SEPARATOR) {
                    $sql = trim($buffer);
                    $buffer = '';
                    if ($sql !== '') {
                        DB::unprepared(rtrim($sql, ';'));
                    }

                    continue;
                }
                if ($buffer === '' && str_starts_with($line, '-- ')) {
                    continue; // тақырып пен қорытынды жолдары
                }
                $buffer .= $line;
            }
        } finally {
            gzclose($gz);
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return list<string> */
    public function tables(): array
    {
        $rows = DB::select("select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' order by table_name");

        return array_map(static fn (object $row): string => (string) $row->name, $rows);
    }

    private function literal(PDO $pdo, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? '1' : '0',
            default => $pdo->quote((string) $value),
        };
    }

    /** @param  resource  $gz */
    private function statement($gz, string $sql): void
    {
        $this->write($gz, $sql.";\n".self::SEPARATOR."\n");
    }

    /** @param  resource  $gz */
    private function write($gz, string $text): void
    {
        if (gzwrite($gz, $text) === false) {
            throw new RuntimeException('Сақтық көшірме файлына жазу мүмкін болмады (диск толған болуы мүмкін).');
        }
    }
}
