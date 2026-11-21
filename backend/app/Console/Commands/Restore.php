<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Дерекқорды сақтық көшірмеден қалпына келтіреді. Тек хостинг панелінен іске қосылады,
 * сайттан емес: бұл әрекет қазіргі деректі түгел ауыстырады. Қате басудан сақтану үшін
 * `--yes` міндетті; онсыз команда не болатынын айтып, ештеңе өзгертпейді.
 */
#[Signature('qatysu:restore {name? : Көшірме файлының атауы; көрсетілмесе ең соңғысы} {--yes : Қалпына келтіруді растау}')]
#[Description('Дерекқорды сақтық көшірмеден қалпына келтіреді')]
class Restore extends Command
{
    public function handle(BackupService $backups): int
    {
        $name = $this->argument('name') ?? ($backups->all()[0]['name'] ?? null);
        $backup = $name === null ? null : $backups->describe((string) $name);

        if ($backup === null) {
            $this->line($name === null ? 'ҚАТЕ: бірде-бір сақтық көшірме жоқ.' : "ҚАТЕ: көшірме табылмады не бүлінген: {$name}");

            return self::FAILURE;
        }

        $summary = "{$backup['name']} · жасалған уақыты {$backup['created_at']} · {$backup['rows']} жол · нұсқа {$backup['version']}";
        if (! $this->option('yes')) {
            $this->line('ЕШТЕҢЕ ӨЗГЕРТІЛГЕН ЖОҚ');
            $this->line('Қалпына келтірілетін көшірме: '.$summary);
            $this->line('Одан кейін енгізілген дерек жоғалады. Растау үшін командаға --yes қосыңыз.');

            return self::FAILURE;
        }

        try {
            $result = $backups->restore($backup['name']);
        } catch (Throwable $e) {
            $this->line('ҚАТЕ: қалпына келтіру сәтсіз. '.mb_substr($e->getMessage(), 0, 200));
            $this->line('Claude-қа хабарлаңыз; қазіргі күйдің көшірмесі сақтаулы болуы мүмкін.');

            return self::FAILURE;
        }

        // Көшірме ескі нұсқада жасалған болса, кестелер қазіргі нұсқаға жеткізіледі.
        Artisan::call('migrate', ['--force' => true]);
        Artisan::call('optimize:clear');

        $this->line('ҚАЛПЫНА КЕЛТІРІЛДІ');
        $this->line($summary);
        if ($result['safety'] !== null) {
            $this->line('Алдыңғы күйдің көшірмесі: '.$result['safety']);
        }
        $this->line('Барлық пайдаланушы қайта кіруі керек.');

        return self::SUCCESS;
    }
}
