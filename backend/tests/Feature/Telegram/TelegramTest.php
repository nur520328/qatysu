<?php

namespace Tests\Feature\Telegram;

use App\Enums\Shift;
use App\Models\NotificationLog;
use App\Models\Report;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\User;
use App\Services\Telegram\NotificationService;
use App\Services\Telegram\TelegramService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Duty\DutyTestCase;

/** Telegram бот: қосу, байланыстыру, хабарлар. Telegram сервері жалған (Http::fake). «Қазір» жұма 2026-11-20, 08:10. Дерек жасанды. */
class TelegramTest extends DutyTestCase
{
    private const TOKEN = '123456:SYNAQ-TOKEN-emes';

    private SchoolClass $a;

    private SchoolClass $b;

    private User $homeroom;

    /** @var array<string, int> */
    private array $ids;

    /** @var list<array{method: string, data: array<string, mixed>, file: ?string}> */
    private array $calls = [];

    /** @var array<string, mixed> */
    private array $override = [];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.token' => self::TOKEN, 'app.url' => 'https://qatysu.example']);
        $this->fakeTelegram();
        $this->homeroom = User::factory()->create(['full_name' => 'Сынақ Жетекші', 'locale' => 'ru']);
        $this->a = $this->classWith('5А', ['Әлібеков Бірінші', 'Сынақов Екінші', 'Үлгіев Үшінші']);
        $this->a->update(['homeroom_user_id' => $this->homeroom->id]);
        $this->b = $this->classWith('6Ә', ['Ойданов Төртінші', 'Тексеров Бесінші']);
        $this->ids = Student::query()->pluck('id', 'last_name')->all();
        $this->actingAs($this->admin);
    }

    /** Әдіс атауы => [күй коды, жауап] не қате лақтыратын функция. Бос болса бәрі сәтті. */
    private function fakeTelegram(array $override = []): void
    {
        $this->override = $override;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (HttpRequest $request) {
            $method = Str::afterLast($request->url(), '/');
            $this->assertStringContainsString('/bot'.self::TOKEN.'/', $request->url());
            $file = null;
            $data = $request->data();
            if ($request->isMultipart()) {
                $fields = [];
                foreach ($data as $part) {
                    if (isset($part['filename'])) {
                        $file = $part['filename'];
                    } else {
                        $fields[$part['name']] = $part['contents'];
                    }
                }
                $data = $fields;
            }
            $this->calls[] = ['method' => $method, 'data' => $data, 'file' => $file];
            $override = $this->override[$method] ?? null;
            if ($override !== null) {
                return $override instanceof Closure ? $override() : Http::response($override[1], $override[0]);
            }

            return Http::response(['ok' => true, 'result' => $method === 'getMe' ? ['id' => 42, 'username' => 'qatysu_synaq_bot'] : []]);
        });
    }

    private function setupBot(): void
    {
        $this->postJson('/api/admin/telegram/setup')->assertOk();
        $this->calls = [];
    }

    private function link(User $user, int $chatId): void
    {
        $token = Str::after(app(TelegramService::class)->createLink($user)['url'], '?start=');
        $this->update("/start {$token}", $chatId)->assertOk();
    }

    private function update(string $text, int $chatId, string $type = 'private', ?string $secret = null)
    {
        return $this->postJson('/api/telegram/webhook', ['update_id' => 1, 'message' => ['chat' => ['id' => $chatId, 'type' => $type], 'text' => $text]],
            ['X-Telegram-Bot-Api-Secret-Token' => $secret ?? app(TelegramService::class)->webhookSecret()]);
    }

    /** @return list<string> */
    private function texts(string $chatId): array
    {
        return array_values(array_map(static fn (array $c): string => (string) ($c['data']['text'] ?? $c['data']['caption']),
            array_filter($this->calls, static fn (array $c): bool => in_array($c['method'], ['sendMessage', 'sendDocument'], true) && (string) $c['data']['chat_id'] === $chatId)));
    }

    private function mark(SchoolClass $class, array $overrides = [], bool $duty = true): void
    {
        $roundId = DB::table('attendance_rounds')->insertGetId([
            'class_id' => $class->id, 'date' => '2026-11-20', 'shift' => $class->shift->value, 'client_uuid' => (string) Str::uuid(),
            'revision' => 1, 'received_late' => false, 'duty_done' => $duty, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $names = DB::table('student_enrollments')->join('students', 'students.id', '=', 'student_enrollments.student_id')
            ->where('class_id', $class->id)->pluck('students.id', 'students.last_name');
        foreach ($names as $name => $studentId) {
            DB::table('attendance_records')->insert(($overrides[$name] ?? []) + [
                'round_id' => $roundId, 'student_id' => $studentId, 'round_status' => 'present', 'round_status_source' => 'duty', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_admin_turns_the_bot_on_from_the_site(): void
    {
        $this->getJson('/api/admin/telegram')->assertOk()->assertJsonPath('token_set', true)->assertJsonPath('username', null);
        $this->getJson('/api/telegram')->assertOk()->assertJsonPath('ready', false);
        $this->postJson('/api/telegram/link')->assertStatus(409)->assertJsonPath('code', 'telegram_not_ready');

        $this->postJson('/api/admin/telegram/setup')->assertOk()->assertJsonPath('username', 'qatysu_synaq_bot')
            ->assertJsonPath('linked_users', 0)->assertJsonPath('webhook_at', '2026-11-20T08:10:00+05:00');

        $this->assertSame(['getMe', 'setWebhook'], array_column($this->calls, 'method'));
        $webhook = $this->calls[1]['data'];
        $this->assertSame('https://qatysu.example/api/telegram/webhook', $webhook['url']);
        $this->assertSame(app(TelegramService::class)->webhookSecret(), $webhook['secret_token']);
        $this->assertSame(['message'], $webhook['allowed_updates']);
        // Токен дерекқорға жазылмайды.
        $this->assertStringNotContainsString(self::TOKEN, json_encode(Setting::query()->get()->toArray()));
    }

    public function test_setup_reports_what_went_wrong(): void
    {
        $this->fakeTelegram(['getMe' => [401, ['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized']]]);
        $this->postJson('/api/admin/telegram/setup')->assertStatus(422)->assertJsonPath('code', 'telegram_token_invalid');

        $this->fakeTelegram(['getMe' => fn () => throw new ConnectionException('cURL error for https://api.telegram.org/bot'.self::TOKEN.'/getMe')]);
        $response = $this->postJson('/api/admin/telegram/setup')->assertStatus(422)->assertJsonPath('code', 'telegram_unreachable');
        $this->assertStringNotContainsString(self::TOKEN, $response->getContent());

        $this->fakeTelegram(['setWebhook' => [400, ['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: bad webhook: HTTPS url must be provided for webhook']]]);
        $this->postJson('/api/admin/telegram/setup')->assertStatus(422)->assertJsonPath('code', 'telegram_rejected')
            ->assertJsonPath('detail', 'Bad Request: bad webhook: HTTPS url must be provided for webhook');
        $this->assertNull(app(TelegramService::class)->username());

        config(['services.telegram.token' => '']);
        $this->postJson('/api/admin/telegram/setup')->assertStatus(422)->assertJsonPath('code', 'telegram_token_missing');
        $this->getJson('/api/admin/telegram')->assertJsonPath('token_set', false);

        $this->actingAs($this->homeroom);
        $this->postJson('/api/admin/telegram/setup')->assertStatus(403);
        $this->getJson('/api/admin/telegram')->assertStatus(403);
    }

    public function test_user_links_account_with_a_one_time_link(): void
    {
        $this->setupBot();
        $this->actingAs($this->homeroom);

        $link = $this->postJson('/api/telegram/link')->assertStatus(201)->assertJsonPath('expires_at', '2026-11-20T08:25:00+05:00')->json('url');
        $this->assertMatchesRegularExpression('#^https://t\.me/qatysu_synaq_bot\?start=[A-Za-z0-9]{40}$#', $link);
        $token = Str::after($link, '?start=');
        // Код дерекқорда ашық түрде жатпайды.
        $this->assertNotSame($token, $this->homeroom->fresh()->telegram_link_hash);
        $this->getJson('/api/telegram')->assertJsonPath('linked', false)->assertJsonPath('bot_username', 'qatysu_synaq_bot');

        $this->update("/start {$token}", 777)->assertOk();
        $this->assertSame('777', $this->homeroom->fresh()->telegram_chat_id);
        $this->assertSame(['Сынақ Жетекші, ваш аккаунт привязан. Теперь сообщения будут приходить в этот чат. Чтобы остановить, напишите /stop.'], $this->texts('777'));
        $this->actingAs($this->homeroom->fresh());
        $this->getJson('/api/telegram')->assertJsonPath('linked', true)->assertJsonPath('linked_at', '2026-11-20T08:10:00+05:00');

        // Код бір-ақ рет жарайды.
        $this->update("/start {$token}", 888)->assertOk();
        $this->assertNull(User::query()->where('telegram_chat_id', '888')->first());
        $this->assertStringContainsString('жарамсыз', $this->texts('888')[0]);
    }

    public function test_expired_link_and_wrong_secret_are_rejected(): void
    {
        $this->setupBot();
        $token = Str::after(app(TelegramService::class)->createLink($this->homeroom)['url'], '?start=');

        $this->update("/start {$token}", 777, secret: 'bogde-qupiya')->assertStatus(403);
        $this->postJson('/api/telegram/webhook', ['message' => ['chat' => ['id' => 777, 'type' => 'private'], 'text' => "/start {$token}"]])->assertStatus(403);
        $this->assertNull($this->homeroom->fresh()->telegram_chat_id);
        $this->assertSame([], $this->calls);

        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 8, 26));
        $this->update("/start {$token}", 777)->assertOk();
        $this->assertNull($this->homeroom->fresh()->telegram_chat_id);
        $this->assertStringContainsString('жарамсыз не мерзімі өтіп кеткен', $this->texts('777')[0]);

        // Жаңа сілтеме алдыңғысын жарамсыз етеді; топтық чатта бот үнсіз.
        $old = Str::after(app(TelegramService::class)->createLink($this->homeroom)['url'], '?start=');
        $new = Str::after(app(TelegramService::class)->createLink($this->homeroom)['url'], '?start=');
        $this->calls = [];
        $this->update("/start {$new}", -100500, 'group')->assertOk();
        $this->assertSame([], $this->calls);
        $this->update("/start {$old}", 777)->assertOk();
        $this->assertNull($this->homeroom->fresh()->telegram_chat_id);
        $this->update("/start {$new}", 777)->assertOk();
        $this->assertSame('777', $this->homeroom->fresh()->telegram_chat_id);
    }

    public function test_one_chat_belongs_to_one_user_and_can_be_unlinked(): void
    {
        $this->setupBot();
        $this->link($this->homeroom, 777);
        $this->link($this->admin, 777);
        $this->assertNull($this->homeroom->fresh()->telegram_chat_id);
        $this->assertSame('777', $this->admin->fresh()->telegram_chat_id);

        $this->calls = [];
        $this->update('сәлем', 777)->assertOk();
        $this->assertStringContainsString('тек хабар жібереді', $this->texts('777')[0]);
        $this->update('/stop', 777)->assertOk();
        $this->assertNull($this->admin->fresh()->telegram_chat_id);
        $this->update('сәлем', 777)->assertOk();
        $this->assertStringContainsString('«Байланыстыру» батырмасын басыңыз', $this->texts('777')[2]);

        $this->link($this->admin, 777);
        $this->actingAs($this->admin->fresh());
        $this->deleteJson('/api/telegram/link')->assertOk()->assertJsonPath('linked', false);
        $this->assertNull($this->admin->fresh()->telegram_chat_id);
    }

    public function test_homeroom_teacher_gets_counts_without_names(): void
    {
        $this->setupBot();
        $this->link($this->homeroom, 777);
        $this->calls = [];

        $this->mark($this->a, ['Әлібеков' => ['round_status' => 'absent'], 'Сынақов' => ['round_status' => 'absent'], 'Үлгіев' => ['left_lesson' => 4]]);
        $this->mark($this->b, ['Ойданов' => ['round_status' => 'absent']]); // жетекшісі жоқ сынып
        Artisan::call('qatysu:notify');
        Artisan::call('qatysu:notify'); // қайталанбауы тиіс

        $this->assertSame([
            '5А: сегодня отсутствует учеников: 2. Указать причину: https://qatysu.example/homeroom/'.$this->a->id.'/2026-11-20',
            '5А: 1 ученик ушёл с 4-го урока. Отметить разрешение: https://qatysu.example/homeroom/'.$this->a->id.'/2026-11-20',
        ], $this->texts('777'));
        $this->assertSame(2, NotificationLog::query()->where('status', 'sent')->count());

        // 19:00-ден кейін: шешім күтіп тұрған үш жазба туралы бір еске салу.
        $this->calls = [];
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 19, 1));
        Artisan::call('qatysu:notify');
        Artisan::call('qatysu:notify');
        $this->assertSame(['Ваше решение ожидают записей: 3 (причина отсутствия или разрешение на уход). Отметьте на сайте: https://qatysu.example/'], $this->texts('777'));

        foreach (NotificationLog::query()->pluck('text') as $text) {
            foreach (array_keys($this->ids) as $name) {
                $this->assertStringNotContainsString($name, $text);
            }
        }
    }

    public function test_admin_gets_shift_summary_conflict_and_frozen_report_file(): void
    {
        $this->setupBot();
        $this->link($this->admin, 555);
        $afternoon = $this->classWith('7Б', ['Қиялов Алтыншы'], Shift::Afternoon);
        $this->issue();
        $this->calls = [];

        // 5А аралынды, 6Ә жоқ: таңғы смена әлі бітпеді, жиынтық жіберілмейді.
        $this->mark($this->a, ['Әлібеков' => ['round_status' => 'absent'], 'Сынақов' => ['round_status' => 'absent', 'arrived_lesson' => 2]]);
        Artisan::call('qatysu:notify');
        $this->assertSame([], $this->texts('555'));

        // Смена бітті (13:10): жиынтық аралынбаған сыныппен.
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 13, 11));
        Artisan::call('qatysu:notify');
        Artisan::call('qatysu:notify');
        $this->assertSame(["Таңғы смена, 20.11.2026.\nАралынды: 1 / 2 сынып.\nКүні бойы жоқ: 1, кешікті: 1, кетіп қалды: 0.\nАралынбаған: 6Ә.\nТолығырақ: https://qatysu.example/admin/summary?date=2026-11-20"], $this->texts('555'));

        // Түстен кейінгі сменаның барлық сыныбы аралынды: смена бітуін күтпей жіберіледі.
        $this->calls = [];
        $this->mark($afternoon);
        DB::table('attendance_round_conflicts')->insert([
            'class_id' => $this->a->id, 'date' => '2026-11-20', 'client_uuid' => (string) Str::uuid(), 'absent_student_ids' => '[]',
            'duty_code_id' => $this->codeId(), 'duty_session_id' => $this->sessionId(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::call('qatysu:notify');
        $texts = $this->texts('555');
        sort($texts);
        $this->assertSame([
            '5А: екі планшет екі түрлі белгілеген, сан әлі түпкілікті емес. Шешу: https://qatysu.example/admin/duty',
            "Түстен кейінгі смена, 20.11.2026.\nАралынды: 1 / 1 сынып.\nКүні бойы жоқ: 0, кешікті: 0, кетіп қалды: 0.\nТолығырақ: https://qatysu.example/admin/summary?date=2026-11-20",
        ], $texts);

        // Апта аяқталды: есеп бекітіледі, завучқа аты-жөнсіз Excel келеді.
        $this->calls = [];
        $this->travelTo(CarbonImmutable::create(2026, 11, 20, 19, 6));
        Artisan::call('qatysu:freeze-reports');
        Artisan::call('qatysu:notify');
        $document = collect($this->calls)->firstWhere('method', 'sendDocument');
        $this->assertSame('qatysu-apta-2026-11-16-jiyntyq.xlsx', $document['file']);
        $this->assertSame("Апталық есеп бекітілді (16.11.2026 – 22.11.2026).\nҚатысу: 75%. Аралынған сынып-күн: 2 / 15.\nСебепсіз: 0, себебі анықталмаған: 1.\nТіркелген файлда аты-жөн жоқ; толық есеп сайтта: https://qatysu.example/admin/reports?date=2026-11-16", $document['data']['caption']);
        $this->assertSame(1, Report::query()->count());
    }

    public function test_failed_messages_are_retried_and_blocked_users_are_unlinked(): void
    {
        $this->setupBot();
        $this->link($this->homeroom, 777);
        $this->mark($this->a, ['Әлібеков' => ['round_status' => 'absent']]);
        $service = app(NotificationService::class);

        // Telegram қолжетімсіз: хабар кезекте қалады.
        $this->fakeTelegram(['sendMessage' => fn () => throw new ConnectionException('timeout')]);
        Artisan::call('qatysu:notify');
        $row = NotificationLog::query()->sole();
        $this->assertSame(['pending', 1, 'unreachable'], [$row->status, $row->attempts, $row->error]);

        // Қайта қолжетімді болды: жіберіледі.
        $this->fakeTelegram();
        $this->assertSame(['sent' => 1, 'failed' => 0, 'left' => 0], $service->send());
        $this->assertSame('sent', $row->fresh()->status);

        // Пайдаланушы ботты бұғаттады: байланыс үзіледі, хабар қайталанбайды.
        $this->mark($this->b);
        DB::table('attendance_records')->where('student_id', $this->ids['Үлгіев'])->update(['left_lesson' => 3]);
        $this->fakeTelegram(['sendMessage' => [403, ['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user']]]);
        Artisan::call('qatysu:notify');
        $this->assertNull($this->homeroom->fresh()->telegram_chat_id);
        $this->assertSame(['failed', 'recipient_gone'], [NotificationLog::query()->latest('id')->first()->status, NotificationLog::query()->latest('id')->first()->error]);

        // 12 сағаттан ескі хабар жіберілмейді.
        $this->link($this->homeroom, 777);
        NotificationLog::query()->create(['user_id' => $this->homeroom->id, 'type' => 'left', 'dedupe_key' => 'eski', 'text' => 'ескі хабар', 'status' => 'pending']);
        $this->travelTo(now()->addHours(13));
        $this->fakeTelegram();
        $this->calls = [];
        $service->send();
        $this->assertSame('expired', NotificationLog::query()->where('dedupe_key', 'eski')->value('status'));
        $this->assertSame([], $this->texts('777'));
    }

    public function test_nothing_is_sent_while_the_bot_is_off_or_nobody_is_linked(): void
    {
        $this->mark($this->a, ['Әлібеков' => ['round_status' => 'absent']]);
        Artisan::call('qatysu:notify');
        $this->setupBot();
        Artisan::call('qatysu:notify');

        $this->assertSame(0, NotificationLog::query()->count());
        $this->assertSame([], $this->calls);
        $this->assertStringContainsString('qatysu:notify', Artisan::call('schedule:list') === 0 ? Artisan::output() : '');
    }

    public function test_bot_texts_have_the_same_keys_and_placeholders_in_three_languages(): void
    {
        $load = static fn (string $locale): array => require base_path("lang/{$locale}/bot.php");
        $placeholders = static fn (array $lines): array => array_map(static function (string $line): array {
            preg_match_all('/:([a-z_]+)/', $line, $m);
            sort($m[1]);

            return array_values(array_unique($m[1]));
        }, $lines);

        $this->assertSame($placeholders($load('kk')), $placeholders($load('ru')));
        $this->assertSame($placeholders($load('kk')), $placeholders($load('zh')));
    }

    private function codeId(): int
    {
        return (int) DB::table('duty_codes')->value('id');
    }

    private function sessionId(): int
    {
        return DB::table('duty_sessions')->insertGetId(['duty_code_id' => $this->codeId(), 'device_number' => 9, 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
