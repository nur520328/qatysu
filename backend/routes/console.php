<?php

use App\Services\Duty\DutyCodeService;
use Illuminate\Support\Facades\Schedule;

// Жоспарлы жұмыстар. Хостинг панелінде минут сайын `php artisan schedule:run` іске қосылады
// (docs/орналастыру.md). Уақыт — қосымшаның уақыт белдеуі бойынша (Asia/Almaty).

// Мерзімі өткен кезекші кодтарының шифрланған көшірмесі тазаланады (көшірмеден бұрын: оған түспеуі үшін).
Schedule::call(fn () => app(DutyCodeService::class)->forgetExpired())->dailyAt('02:00')->name('forget-expired-duty-codes');

// Түнгі сақтық көшірме: сабақ жоқ, сайтта ешкім жұмыс істемейтін уақыт.
Schedule::command('qatysu:backup')->dailyAt('02:30')->withoutOverlapping();
