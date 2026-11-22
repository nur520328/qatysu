<?php

namespace App\Console\Commands;

use App\Services\Telegram\NotificationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** Минут сайын жоспарлағыштан іске қосылады (routes/console.php). Бот қосылмаған болса ештеңе істемейді. */
#[Signature('qatysu:notify')]
#[Description('Telegram хабарларын кезекке қойып, жібереді')]
class Notify extends Command
{
    public function handle(NotificationService $notifications): int
    {
        $queued = $notifications->collect();
        $result = $notifications->send();
        $this->line("Кезекке қосылды: {$queued}. Жіберілді: {$result['sent']}, сәтсіз: {$result['failed']}, кезекте қалды: {$result['left']}.");

        return self::SUCCESS;
    }
}
