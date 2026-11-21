<?php

use Illuminate\Support\Facades\Schedule;

// Жоспарлы жұмыстар. Хостинг панелінде минут сайын `php artisan schedule:run` іске қосылады
// (docs/орналастыру.md). Уақыт — қосымшаның уақыт белдеуі бойынша (Asia/Almaty).

// Түнгі сақтық көшірме: сабақ жоқ, сайтта ешкім жұмыс істемейтін уақыт.
Schedule::command('qatysu:backup')->dailyAt('02:30')->withoutOverlapping();
