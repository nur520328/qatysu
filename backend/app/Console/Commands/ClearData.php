<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use App\Services\Maintenance\DataResetService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Сынақ дерегін тазалайды, нақты тізімді салар алдында бір рет қолданылады. Тек хостинг панелінен іске қосылады.
 * Қорғаныс: `--yes` болмаса тек не өшірілетінін айтады; өшірер алдында міндетті сақтық көшірме жасайды;
 * қатысу көп күнге белгіленген болса (нақты жұмысқа ұқсаса), бөлек растаусыз тоқтайды.
 */
#[Signature('qatysu:clear-data {--yes : Өшіруді растау} {--even-if-in-use : Қатысу көп күнге белгіленген болса да өшіру}')]
#[Description('Сынақ дерегін өшіреді: оқушылар, сыныптар, мұғалімдер, қатысу жазбалары. Завуч пен баптау қалады')]
class ClearData extends Command
{
    /** Осыдан көп күнге қатысу белгіленген болса, дерек сынаққа ұқсамайды. */
    public const TEST_DAYS_LIMIT = 10;

    public function handle(DataResetService $reset, BackupService $backups): int
    {
        $counts = $reset->counts();
        $what = "оқушы {$counts['students']}, сынып {$counts['classes']}, мұғалім {$counts['teachers']}, қатысу жазбасы {$counts['records']} ({$counts['days']} күн)";
        $kept = "завуч аккаунты ({$counts['admins']}), баптау, оқу жылы мен күнтізбе, сақтық көшірмелер";

        if ($counts['days'] > self::TEST_DAYS_LIMIT && ! $this->option('even-if-in-use')) {
            $this->line('ТОҚТАТЫЛДЫ: ештеңе өшірілген жоқ.');
            $this->line("Қатысу {$counts['days']} түрлі күнге белгіленген. Бұл сынақ дерегіне ұқсамайды, нақты жұмыс дерегі болуы мүмкін.");
            $this->line('Claude-қа хабарласыңыз.');
            $this->history($counts, 'stopped_in_use', null);

            return self::FAILURE;
        }

        if (! $this->option('yes')) {
            $this->line('ЕШТЕҢЕ ӨШІРІЛГЕН ЖОҚ');
            $this->line('Өшірілетіні: '.$what.'.');
            $this->line('Қалатыны: '.$kept.'.');
            $this->line('Растау үшін командаға --yes қосыңыз.');

            return self::FAILURE;
        }

        try {
            $backup = $backups->create(required: true);
        } catch (Throwable $e) {
            $this->line('ТОҚТАТЫЛДЫ: ештеңе өшірілген жоқ, себебі алдын ала сақтық көшірме жасалмады.');
            $this->line('Себебі: '.(mb_substr($e->getMessage(), 0, 200) ?: $e::class));
            $this->history($counts, 'backup_failed', null);

            return self::FAILURE;
        }

        try {
            $reset->clear();
        } catch (Throwable $e) {
            $this->line('ҚАТЕ: тазалау орындалмады, дерек бұрынғы күйінде. '.(mb_substr($e->getMessage(), 0, 200) ?: $e::class));
            $this->history($counts, 'failed', $backup['name']);

            return self::FAILURE;
        }

        $this->line('ТАЗАЛАНДЫ');
        $this->line('Өшірілді: '.$what.'.');
        $this->line('Қалды: '.$kept.'.');
        $this->line('Тазалау алдындағы көшірме: '.$backup['name']);
        $this->line('Мұғалімдер мен планшеттер жүйеден шығарылды; завуч бұрынғы паролімен кіреді.');
        $this->history($counts, 'cleared', $backup['name']);

        return self::SUCCESS;
    }

    /** @param  array<string, int>  $counts */
    private function history(array $counts, string $result, ?string $backup): void
    {
        $line = json_encode([
            'at' => now()->toIso8601String(),
            'action' => 'clear-data',
            'version' => (string) config('app.version'),
            'before' => $counts,
            'backup' => $backup,
            'result' => $result,
        ], JSON_UNESCAPED_UNICODE);
        @file_put_contents(Install::historyPath(), $line."\n", FILE_APPEND | LOCK_EX);
    }
}
