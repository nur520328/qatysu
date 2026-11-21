<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\OneTimePassword;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Парольді ұмытқан (не бір реттік паролін алып үлгермеген) аккаунтқа жаңа бір реттік
 * пароль береді. Әкімші өз паролін жоғалтса, оны қалпына келтірудің жалғыз жолы осы:
 * команда хостинг панеліндегі жоспарлаушыдан іске қосылады, яғни оны тек хостинг иесі
 * орындай алады.
 */
#[Signature('qatysu:reset-password {login : Аккаунттың логині}')]
#[Description('Аккаунтқа жаңа бір реттік пароль береді және оның ашық сеанстарын жабады')]
class ResetPassword extends Command
{
    public function handle(): int
    {
        $login = User::normalizeLogin((string) $this->argument('login'));
        $user = User::query()->where('login', $login)->first();

        if ($user === null) {
            $this->line("ҚАТЕ: «{$login}» логині табылмады.");

            return self::FAILURE;
        }

        $password = OneTimePassword::generate();
        $user->forceFill(['password' => $password, 'must_change_password' => true])->save();

        // Ескі парольмен ашылған сеанстар жабылады.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $this->line("Бір реттік пароль: {$password}");
        $this->line("Логин: {$login}. Алғаш кіргенде пароль ауыстырылады.");

        return self::SUCCESS;
    }
}
