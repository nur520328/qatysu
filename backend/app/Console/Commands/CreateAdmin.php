<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

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
            $this->error('Логин не аты-жөні бос немесе тым ұзын.');

            return self::FAILURE;
        }
        if (User::query()->where('login', $login)->exists()) {
            $this->error("«{$login}» логині бос емес.");

            return self::FAILURE;
        }

        // 12 таңба: 10 әріп пен 2 сан, яғни пароль ережесіне әрдайым сай келеді.
        $password = Str::password(10, letters: true, numbers: false, symbols: false)
            .random_int(10, 99);

        User::query()->create([
            'full_name' => $fullName,
            'login' => $login,
            'password' => $password,
            'role' => Role::Admin,
            'must_change_password' => true,
        ]);

        $this->info("Әкімші ашылды: {$login}");
        $this->line("Бір реттік пароль: {$password}");
        $this->line('Алғаш кіргенде пароль ауыстырылады. Осы шығысты сақтамаңыз.');

        return self::SUCCESS;
    }
}
