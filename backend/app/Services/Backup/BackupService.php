<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Сақтық көшірмелерді жасайды, тізімдейді, ескілерін өшіреді және қалпына келтіреді.
 * Файлдар `storage/app/backups` қалтасында: ол сайттың ашық қалтасынан (public) тыс,
 * яғни браузерден тікелей ашылмайды; жүктеп алу тек әкімшіге API арқылы.
 */
class BackupService
{
    /** Соңғы көшірме осыдан ескі болса, әкімшіге ескерту көрсетіледі (түнгі көшірме + қор). */
    public const STALE_AFTER_HOURS = 26;

    public const DAILY_DAYS = 30;

    public const WEEKLY_WEEKS = 8;

    /** Қолмен жасалатын көшірмелер дискіні толтырмауы үшін. */
    public const MAX_PER_DAY = 5;

    public const NAME_PATTERN = '/^qatysu-(\d{8})-(\d{6})\.sql\.gz$/';

    public function __construct(private readonly DatabaseDumper $dumper) {}

    public function directory(): string
    {
        $dir = storage_path('app/backups');
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Сақтық көшірме қалтасы жасалмады: {$dir}");
        }

        return $dir;
    }

    /**
     * @return array{name: string, size: int, created_at: string, version: string, tables: int, rows: int}
     */
    public function create(): array
    {
        $now = CarbonImmutable::now();
        $today = array_filter($this->all(), static fn (array $b): bool => str_starts_with($b['name'], 'qatysu-'.$now->format('Ymd')));
        if (count($today) >= self::MAX_PER_DAY) {
            throw new BackupLimitReached;
        }

        $name = 'qatysu-'.$now->format('Ymd-His').'.sql.gz';
        $path = $this->directory().'/'.$name;
        $partial = $path.'.part';

        try {
            $this->dumper->dump($partial);
            if ($this->dumper->inspect($partial) === null) {
                throw new RuntimeException('Жазылған файл тексерістен өтпеді.');
            }
            if (! rename($partial, $path)) {
                throw new RuntimeException('Файлдың атауы өзгертілмеді.');
            }
        } catch (\Throwable $e) {
            @unlink($partial);
            Log::error('Сақтық көшірме жасалмады: '.$e->getMessage());
            throw $e;
        }

        @chmod($path, 0600);
        $this->prune($now);

        return $this->describe($name) ?? throw new RuntimeException('Жаңа көшірме табылмады.');
    }

    /**
     * Толық жазылған көшірмелер, жаңасынан ескісіне қарай.
     *
     * @return list<array{name: string, size: int, created_at: string, version: string, tables: int, rows: int}>
     */
    public function all(): array
    {
        $names = array_filter((array) scandir($this->directory()), static fn ($n): bool => is_string($n) && preg_match(self::NAME_PATTERN, $n) === 1);
        rsort($names);

        return array_values(array_filter(array_map($this->describe(...), $names)));
    }

    /** @return array{name: string, size: int, created_at: string, version: string, tables: int, rows: int}|null */
    public function describe(string $name): ?array
    {
        $path = $this->path($name);
        if ($path === null) {
            return null;
        }
        // Файлдың толықтығын тексеру оны басынан аяғына дейін оқуды талап етеді. Тізім әр ашылғанда
        // ондаған файлды қайта оқымас үшін нәтиже есте сақталады; файл өзгерсе (көлемі не уақыты), қайта тексеріледі.
        $key = filesize($path).':'.filemtime($path);
        $index = $this->index();
        if (($index[$name]['key'] ?? null) !== $key) {
            $index[$name] = ['key' => $key, 'meta' => $this->dumper->inspect($path)];
            $this->saveIndex($index);
        }
        $meta = $index[$name]['meta'];

        return $meta === null ? null : ['name' => $name, 'size' => (int) filesize($path)] + $meta;
    }

    /** @return array<string, array{key: string, meta: array{version: string, created_at: string, tables: int, rows: int}|null}> */
    private function index(): array
    {
        $data = json_decode((string) @file_get_contents($this->directory().'/index.json'), true);

        return is_array($data) ? $data : [];
    }

    /** @param  array<string, mixed>  $index */
    private function saveIndex(array $index): void
    {
        // Өшірілген файлдардың жазбалары жиналып қалмауы тиіс.
        $index = array_filter($index, fn (string $name): bool => is_file($this->directory().'/'.$name), ARRAY_FILTER_USE_KEY);
        @file_put_contents($this->directory().'/index.json', json_encode($index), LOCK_EX);
    }

    /** Атау пішімге сай және файл бар болса ғана жол қайтарады (жол арқылы басқа файлға жету мүмкін емес). */
    public function path(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        $path = $this->directory().'/'.$name;

        return is_file($path) ? $path : null;
    }

    /**
     * @return array{last_at: string|null, stale: bool}
     */
    public function health(): array
    {
        $last = $this->all()[0]['created_at'] ?? null;
        $stale = $last === null || CarbonImmutable::parse($last)->lt(CarbonImmutable::now()->subHours(self::STALE_AFTER_HOURS));

        return ['last_at' => $last, 'stale' => $stale];
    }

    /**
     * Алдымен қазіргі күйдің көшірмесін жасайды (қате файл таңдалса, кері қайтуға болады), содан кейін қалпына келтіреді.
     *
     * @return array{restored: string, safety: string|null}
     */
    public function restore(string $name): array
    {
        $path = $this->path($name);
        if ($path === null || $this->dumper->inspect($path) === null) {
            throw new RuntimeException("Көшірме табылмады не бүлінген: {$name}");
        }

        $safety = null;
        if ($this->dumper->tables() !== []) {
            $safetyName = 'qatysu-'.CarbonImmutable::now()->format('Ymd-His').'.sql.gz';
            if ($safetyName === $name) {
                throw new RuntimeException('Бір секунд күтіп, қайталаңыз.');
            }
            $this->dumper->dump($this->directory().'/'.$safetyName);
            $safety = $safetyName;
        }

        $this->dumper->restore($path);
        Log::warning("Дерекқор көшірмеден қалпына келтірілді: {$name}");

        return ['restored' => $name, 'safety' => $safety];
    }

    /**
     * Сақталады: соңғы 30 күннің әр күніндегі соңғы көшірме (бүгінгілердің бәрі),
     * одан ескілерінен соңғы 8 аптаның әр аптасындағы соңғы көшірме. Қалғаны өшіріледі.
     */
    public function prune(CarbonImmutable $now): int
    {
        $keptDays = [];
        $keptWeeks = [];
        $removed = 0;

        foreach ($this->fileNames() as $name) {
            preg_match(self::NAME_PATTERN, $name, $m);
            $day = CarbonImmutable::createFromFormat('Ymd', $m[1])->startOfDay();
            $age = (int) $day->diffInDays($now->startOfDay());

            if ($age === 0) {
                continue;
            }
            if ($age < self::DAILY_DAYS) {
                $keep = ! isset($keptDays[$m[1]]);
                $keptDays[$m[1]] = true;
            } elseif ($age < self::DAILY_DAYS + self::WEEKLY_WEEKS * 7) {
                $week = $day->format('o-W');
                $keep = ! isset($keptWeeks[$week]);
                $keptWeeks[$week] = true;
            } else {
                $keep = false;
            }
            if (! $keep && @unlink($this->directory().'/'.$name)) {
                $removed++;
            }
        }

        // Үзіліп қалған жазбалардың қалдығы.
        foreach ((array) glob($this->directory().'/*.part') as $partial) {
            if (is_string($partial) && filemtime($partial) < $now->subHour()->getTimestamp()) {
                @unlink($partial);
            }
        }

        return $removed;
    }

    /** @return list<string> жаңасынан ескісіне қарай */
    private function fileNames(): array
    {
        $names = array_values(array_filter((array) scandir($this->directory()), static fn ($n): bool => is_string($n) && preg_match(self::NAME_PATTERN, $n) === 1));
        rsort($names);

        return $names;
    }
}
