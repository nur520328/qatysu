<?php

namespace App\Console\Commands;

use App\Services\Reports\ReportSnapshotService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Сағат сайын жоспарлағыштан іске қосылады (routes/console.php). Қолмен іске қосу да қауіпсіз: қайталанбайды. */
#[Signature('qatysu:freeze-reports')]
#[Description('Аяқталған кезеңдердің есептерін бекітеді (сол сәттегі сурет сақталады)')]
class FreezeReports extends Command
{
    public function handle(ReportSnapshotService $snapshots): int
    {
        $created = $snapshots->freezeDue();
        $this->line($created === [] ? 'Бекітілетін есеп жоқ.' : 'Бекітілді: '.implode(', ', array_map(
            static fn ($r): string => ($r->type === 'weekly' ? 'апта ' : 'ай ').$r->period_start->toDateString().' – '.$r->period_end->toDateString(), $created)));

        return self::SUCCESS;
    }
}
