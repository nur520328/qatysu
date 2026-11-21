<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Серверге орналастырудың жалғыз командасы. Хостингте SSH жоқ, сондықтан ол панельдегі
 * жоспарлаушыдан іске қосылады және бірнеше рет қауіпсіз қайталанады:
 * ортаны тексереді, шифрлау кілтін (жоқ болса) жасайды, дерекқор кестелерін жаңартады,
 * әкімші жоқ болса алғашқысын ашады.
 */
#[Signature('qatysu:install {login? : Алғашқы әкімшінің логині} {full_name? : Оның аты-жөні (көрсетілмесе «Әкімші»)}')]
#[Description('Ортаны тексеріп, кестелерді жаңартады; қажет болса алғашқы әкімшіні ашады')]
class Install extends Command
{
    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'openssl', 'ctype', 'fileinfo', 'tokenizer', 'xml', 'curl'];

    public function handle(): int
    {
        $this->line('=== Қатысу платформасы: орнату ===');
        $this->line('Нұсқа: '.config('app.version'));
        $this->line('PHP: '.PHP_VERSION);

        $problems = $this->checkEnvironment();
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error('ҚАТЕ: '.$problem);
            }
            $this->line('Жоғарыдағы қателер түзелмейінше ештеңе өзгертілген жоқ.');

            return self::FAILURE;
        }

        if (config('app.key') === null || config('app.key') === '') {
            Artisan::call('key:generate', ['--force' => true]);
            $this->line('Шифрлау кілті жасалып, .env файлына жазылды.');
        } else {
            $this->line('Шифрлау кілті бар, өзгертілген жоқ.');
        }

        $this->alignDatabaseCharset();

        Artisan::call('migrate', ['--force' => true]);
        $this->line('Дерекқор кестелері: '.trim(preg_replace('/\s+/', ' ', Artisan::output())));

        $wrongTables = $this->tablesWithWrongCharset();
        if ($wrongTables !== []) {
            $this->error('ҚАТЕ: мына кестелердің кодталуы utf8mb4 емес: '.implode(', ', $wrongTables)
                .'. Қазақ әріптері дұрыс сақталмауы мүмкін. Claude-қа хабарлаңыз.');

            return self::FAILURE;
        }
        $this->line('Кестелердің кодталуы: utf8mb4 (қазақ, орыс, қытай әріптеріне жарайды).');

        // Кілт пен баптау өзгерген болуы мүмкін; ескі кэш қалмауы тиіс.
        Artisan::call('optimize:clear');

        $result = $this->ensureAdmin();
        $this->reportTelegram();
        $this->line($result === self::SUCCESS ? '=== Орнату сәтті аяқталды ===' : '=== Орнату аяқталмады ===');

        return $result;
    }

    /** @return list<string> */
    private function checkEnvironment(): array
    {
        $problems = [];

        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            $problems[] = 'PHP 8.3 не одан жоғары керек, ал мұнда '.PHP_VERSION.'. Панельден PHP нұсқасын ауыстырыңыз.';
        }
        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            if (! extension_loaded($extension)) {
                $problems[] = "PHP кеңейтімі жоқ: {$extension}";
            }
        }
        foreach ([storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache')] as $dir) {
            if (! is_dir($dir) || ! is_writable($dir)) {
                $problems[] = "Қалтаға жазу мүмкін емес: {$dir}";
            }
        }
        if (! is_file(base_path('.env'))) {
            $problems[] = '.env файлы жоқ. Оны .env.example.production файлынан көшіріп жасаңыз.';
        } elseif (! is_writable(base_path('.env')) && (config('app.key') === null || config('app.key') === '')) {
            $problems[] = '.env файлына жазу мүмкін емес, сондықтан шифрлау кілті сақталмайды.';
        }

        try {
            $version = DB::selectOne('select version() as v')->v;
            $this->line('Дерекқор: '.$version);
        } catch (Throwable $e) {
            $problems[] = 'Дерекқорға қосылу мүмкін болмады. .env файлындағы DB_ мәндерін (әсіресе парольді) тексеріңіз. Себебі: '
                .$this->safeMessage($e);
        }

        return $problems;
    }

    /**
     * Хостинг панелі дерекқорды ескі `utf8mb3` кодталуымен ашуы мүмкін. Біздің кестелер
     * кодталуын өздері көрсетеді (utf8mb4), сондықтан бұл оларға әсер етпейді; дегенмен
     * дерекқордың әдепкі мәнін де түзеп қоямыз. Құқық жетпесе, бұл қате емес.
     */
    private function alignDatabaseCharset(): void
    {
        $current = DB::selectOne('select @@character_set_database as c')->c;
        if ($current === 'utf8mb4') {
            return;
        }

        try {
            $name = str_replace('`', '``', (string) DB::connection()->getDatabaseName());
            DB::statement("alter database `{$name}` character set utf8mb4 collate utf8mb4_unicode_ci");
            $this->line("Дерекқордың әдепкі кодталуы {$current} еді, utf8mb4-ке ауыстырылды.");
        } catch (Throwable) {
            $this->line("Дерекқордың әдепкі кодталуы {$current} (өзгертуге құқық жоқ). Кестелер бәрібір utf8mb4-пен ашылады.");
        }
    }

    /** @return list<string> */
    private function tablesWithWrongCharset(): array
    {
        $rows = DB::select(
            "select table_name as name from information_schema.tables
             where table_schema = database() and table_type = 'BASE TABLE'
               and table_collation not like 'utf8mb4%'"
        );

        return array_map(static fn (object $row): string => $row->name, $rows);
    }

    private function ensureAdmin(): int
    {
        if (User::query()->where('role', Role::Admin)->exists()) {
            $this->line('Әкімші аккаунты бар, жаңасы ашылған жоқ.');

            return self::SUCCESS;
        }

        $login = $this->argument('login');
        if ($login === null) {
            $this->error('Әкімші әлі жоқ. Команданы логинді қосып қайта іске қосыңыз: qatysu:install <логин>');

            return self::FAILURE;
        }
        // Хостинг жоспарлаушысында бос орны бар не кирилл аргумент беру сенімсіз,
        // сондықтан аты-жөні міндетті емес. Әкімші оны кейін баптаудан өзгертеді.
        $fullName = $this->argument('full_name') ?? 'Әкімші';

        $exit = Artisan::call('qatysu:create-admin', ['login' => $login, 'full_name' => $fullName]);
        $this->line(trim(Artisan::output()));

        return $exit;
    }

    /** Бот 10-кезеңде қосылады; мұнда тек хостингтен Telegram-ға шығу мүмкін бе, сол тексеріледі. */
    private function reportTelegram(): void
    {
        try {
            $status = Http::timeout(8)->get('https://api.telegram.org')->status();
            $this->line("Telegram-ға шығу: бар (жауап коды {$status}).");
        } catch (Throwable $e) {
            $this->warn('Telegram-ға шығу: ЖОҚ. Бот үшін хостинг қолдауына жүгіну керек болады. Себебі: '.$this->safeMessage($e));
        }
    }

    /** Қате мәтінінде пароль кездесіп қалса, оны шығысқа жібермейді. */
    private function safeMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        $password = (string) config('database.connections.'.config('database.default').'.password');

        return $password === '' ? $message : str_replace($password, '***', $message);
    }
}
