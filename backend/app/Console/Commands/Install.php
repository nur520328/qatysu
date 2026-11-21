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
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Серверге орналастырудың жалғыз командасы. Хостингте SSH жоқ, сондықтан ол панельдегі
 * жоспарлаушыдан іске қосылады және бірнеше рет қауіпсіз қайталанады:
 * ортаны тексереді, шифрлау кілтін (жоқ болса) жасайды, дерекқор кестелерін жаңартады,
 * әкімші жоқ болса алғашқысын ашады.
 *
 * Шығыс әдейі қысқа: хостинг панелі оның алғашқы ~450 таңбасын ғана көрсетеді
 * (2026-11-21 күні Hoster.kz-те анықталды). Сондықтан ең маңызды жолдар — нәтиже мен
 * бір реттік пароль — ең басында тұрады, ал егжей-тегжей соңында.
 */
#[Signature('qatysu:install {login? : Алғашқы әкімшінің логині} {full_name? : Оның аты-жөні (көрсетілмесе «Әкімші»)} {--accept-empty-database : Бұрын орнатылған жерде дерекқордың бос болуы күтілген (мысалы, әдейі тазаланған)}')]
#[Description('Ортаны тексеріп, кестелерді жаңартады; қажет болса алғашқы әкімшіні ашады')]
class Install extends Command
{
    /** Соңғы жолдағылар Excel файлдарын оқу мен жазуға керек. */
    private const REQUIRED_EXTENSIONS = [
        'pdo_mysql', 'mbstring', 'openssl', 'ctype', 'fileinfo', 'tokenizer', 'xml', 'curl',
        'zip', 'zlib', 'dom', 'simplexml', 'xmlreader', 'xmlwriter', 'iconv',
    ];

    /** @var list<string> Соңында шығатын егжей-тегжей жолдары. */
    private array $details = [];

    public function handle(): int
    {
        $this->details = [];

        $problems = $this->checkEnvironment();
        if ($problems !== []) {
            $lines = array_map(static fn (string $problem): string => 'ҚАТЕ: '.$problem, $problems);
            $lines[] = 'Ештеңе өзгертілген жоқ.';

            return $this->finish(false, $lines);
        }

        if (config('app.key') === null || config('app.key') === '') {
            Artisan::call('key:generate', ['--force' => true]);
            $this->details[] = 'Шифрлау кілті жасалды.';
        }

        // 2026-11-21 күні хостингте жаңарту кезінде дерекқор күтпеген жерден бос болып шықты,
        // ал команда үнсіз жаңа бос жүйе құрды. Енді мұндайда тоқтап, адамнан растау сұрайды.
        if ($this->databaseUnexpectedlyEmpty() && ! $this->option('accept-empty-database')) {
            return $this->finish(false, [
                'ТОҚТАТЫЛДЫ: бұл жерде жүйе бұрын орнатылған ('.$this->marker()['installed_at'].'), бірақ дерекқор бос.',
                'Дерек жоғалған болуы мүмкін. Ештеңе өзгертілген жоқ. Сақтық көшірмеден қалпына келтіріңіз не Claude-қа хабарлаңыз.',
                'Бос дерекқор әдейі болса: командаға --accept-empty-database қосыңыз.',
            ]);
        }

        $this->alignDatabaseCharset();

        $before = $this->migrationCount();
        Artisan::call('migrate', ['--force' => true]);
        $ran = $this->migrationCount() - $before;
        $this->details[] = $ran > 0 ? "Кестелер жаңартылды ({$ran} миграция)." : 'Кестелер өзгеріссіз.';

        $wrongTables = $this->tablesWithWrongCharset();
        if ($wrongTables !== []) {
            return $this->finish(false, [
                'ҚАТЕ: кестелердің кодталуы utf8mb4 емес: '.implode(', ', $wrongTables).'. Claude-қа хабарлаңыз.',
            ]);
        }

        // Кілт пен баптау өзгерген болуы мүмкін; ескі кэш қалмауы тиіс.
        Artisan::call('optimize:clear');

        [$ok, $adminLines] = $this->ensureAdmin();
        if ($ok) {
            $this->writeMarker();
        }
        $this->details[] = $this->telegramStatus();

        return $this->finish($ok, $adminLines);
    }

    /** @param  list<string>  $headline  Ең алдымен көрсетілетін жолдар. */
    private function finish(bool $ok, array $headline): int
    {
        $this->line($ok ? 'ОРНАТУ СӘТТІ' : 'ОРНАТУ АЯҚТАЛМАДЫ');
        foreach ($headline as $line) {
            $this->line($line);
        }
        $this->line('Қатысу '.config('app.version').' · PHP '.PHP_VERSION.$this->databaseLabel());
        foreach ($this->details as $line) {
            $this->line($line);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    private function checkEnvironment(): array
    {
        $problems = [];

        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            $problems[] = 'PHP 8.3 не одан жоғары керек, ал мұнда '.PHP_VERSION.'.';
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
            $problems[] = '.env файлы жоқ. Оны .env.example.production файлынан жасаңыз.';
        } elseif (! is_writable(base_path('.env')) && (config('app.key') === null || config('app.key') === '')) {
            $problems[] = '.env файлына жазу мүмкін емес, шифрлау кілті сақталмайды.';
        }

        try {
            DB::selectOne('select 1');
        } catch (Throwable $e) {
            $problems[] = 'Дерекқорға қосылу мүмкін болмады. .env файлындағы DB_ мәндерін (әсіресе парольді) тексеріңіз. '
                .$this->safeMessage($e);
        }

        return $problems;
    }

    private function databaseLabel(): string
    {
        try {
            $version = (string) DB::selectOne('select version() as v')->v;

            return ' · '.(str_contains($version, 'MariaDB') ? 'MariaDB ' : 'MySQL ').strtok($version, '-');
        } catch (Throwable) {
            return '';
        }
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
            $this->details[] = "Дерекқор кодталуы {$current} → utf8mb4.";
        } catch (Throwable) {
            $this->details[] = "Дерекқордың әдепкі кодталуы {$current} (өзгертуге құқық жоқ; кестелер utf8mb4).";
        }
    }

    /** Орнату белгісі дерекқордан тыс сақталады: дерекқор жоғалса да, бұрын орнатылғаны белгілі болады. */
    public static function markerPath(): string
    {
        return storage_path('app/install-marker.json');
    }

    /** @return array{installed_at: string, version: string}|null */
    private function marker(): ?array
    {
        if (! is_file(self::markerPath())) {
            return null;
        }
        $data = json_decode((string) file_get_contents(self::markerPath()), true);

        return is_array($data) && isset($data['installed_at'], $data['version']) ? $data : null;
    }

    private function writeMarker(): void
    {
        $data = ['installed_at' => $this->marker()['installed_at'] ?? now()->toDateString(), 'version' => (string) config('app.version')];
        if (@file_put_contents(self::markerPath(), json_encode($data)) === false) {
            $this->details[] = 'Ескерту: орнату белгісі жазылмады (storage/app).';
        }
    }

    private function databaseUnexpectedlyEmpty(): bool
    {
        if ($this->marker() === null) {
            return false;
        }

        return ! Schema::hasTable('users') || ! User::query()->exists();
    }

    private function migrationCount(): int
    {
        return Schema::hasTable('migrations') ? DB::table('migrations')->count() : 0;
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

    /** @return array{bool, list<string>} */
    private function ensureAdmin(): array
    {
        if (User::query()->where('role', Role::Admin)->exists()) {
            return [true, ['Әкімші аккаунты бар, жаңасы ашылған жоқ.']];
        }

        $login = $this->argument('login');
        if ($login === null) {
            return [false, ['Әкімші әлі жоқ. Команданың соңына логинді қосып қайта іске қосыңыз.']];
        }

        // Хостинг жоспарлаушысында бос орны бар не кирилл аргумент беру сенімсіз,
        // сондықтан аты-жөні міндетті емес. Әкімші оны кейін баптаудан өзгертеді.
        $exit = Artisan::call('qatysu:create-admin', [
            'login' => $login,
            'full_name' => $this->argument('full_name') ?? 'Әкімші',
        ]);
        $lines = array_values(array_filter(array_map('trim', explode("\n", Artisan::output()))));

        return [$exit === self::SUCCESS, $lines];
    }

    /** Бот 10-кезеңде қосылады; мұнда тек хостингтен Telegram-ға шығу мүмкін бе, сол тексеріледі. */
    private function telegramStatus(): string
    {
        try {
            Http::timeout(8)->get('https://api.telegram.org')->status();

            return 'Telegram-ға шығу: бар.';
        } catch (Throwable) {
            return 'Telegram-ға шығу: ЖОҚ.';
        }
    }

    /** Қате мәтінінде пароль кездесіп қалса, оны шығысқа жібермейді. */
    private function safeMessage(Throwable $e): string
    {
        $message = mb_substr($e->getMessage(), 0, 160);
        $password = (string) config('database.connections.'.config('database.default').'.password');

        return $password === '' ? $message : str_replace($password, '***', $message);
    }
}
