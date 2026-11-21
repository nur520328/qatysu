<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use App\Support\OneTimePassword;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Жаңа орнатылған серверде алғашқы әкімшіні ашады.
 * Хостингте SSH жоқ, сондықтан бұл команда панельдегі жоспарлаушы арқылы бір рет іске
 * қосылады. Пароль аргумент ретінде берілмейді (ол жоспарлаушы тарихында қалып қояды):
 * команда бір реттік пароль жасап, оны шығысқа бір-ақ рет жазады.
 */
#[Signature('qatysu:create-admin {login : Әкімшінің логині} {full_name : Аты-жөні}')]
#[Description('Алғашқы әкімші аккаунтын бір реттік парольмен ашады')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $login = User::normalizeLogin((string) $this->argument('login'));
        $fullName = trim((string) $this->argument('full_name'));

        if ($login === '' || $fullName === '' || mb_strlen($login) > 60 || mb_strlen($fullName) > 150) {
            $this->line('ҚАТЕ: логин не аты-жөні бос немесе тым ұзын.');

            return self::FAILURE;
        }
        if (User::query()->where('login', $login)->exists()) {
            $this->line("ҚАТЕ: «{$login}» логині бос емес.");

            return self::FAILURE;
        }

        $password = OneTimePassword::generate();

        User::query()->create([
            'full_name' => $fullName,
            'login' => $login,
            'password' => $password,
            'role' => Role::Admin,
            'must_change_password' => true,
        ]);

        // Пароль бірінші жолда: хостинг панелі шығыстың басын ғана көрсетеді.
        $this->line("Бір реттік пароль: {$password}");
        $this->line("Әкімші ашылды: {$login}. Алғаш кіргенде пароль ауыстырылады.");

        return self::SUCCESS;
    }
}
