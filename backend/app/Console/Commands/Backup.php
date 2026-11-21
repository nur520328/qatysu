<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupLimitReached;
use App\Services\Backup\BackupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/** Күн сайын жоспарлаушыдан іске қосылады; қолмен де іске қосуға болады. Шығысы қысқа (панель шегі). */
#[Signature('qatysu:backup')]
#[Description('Дерекқордың сақтық көшірмесін жасап, ескілерін өшіреді')]
class Backup extends Command
{
    public function handle(BackupService $backups): int
    {
        try {
            $backup = $backups->create();
        } catch (BackupLimitReached) {
            $this->line('Бүгін көшірме жеткілікті жасалған ('.BackupService::MAX_PER_DAY.'), жаңасы жасалған жоқ.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line('ҚАТЕ: сақтық көшірме жасалмады. '.mb_substr($e->getMessage() !== '' ? $e->getMessage() : class_basename($e), 0, 200));

            return self::FAILURE;
        }

        $this->line('КӨШІРМЕ ЖАСАЛДЫ');
        $this->line("{$backup['name']} · {$backup['tables']} кесте, {$backup['rows']} жол, ".round($backup['size'] / 1024).' КБ');
        $this->line('Сақтаулы көшірме саны: '.count($backups->all()));

        return self::SUCCESS;
    }
}
