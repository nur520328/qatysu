<?php

namespace App\Services\Telegram;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Ботты қосу, аккаунтты байланыстыру және келген хабарларға жауап беру (docs/кезең-10-жоспар.md).
 * Бот тек хабарлайды: келген мәтіннен дерек оқылмайды, тек `/start <код>` пен `/stop` өңделеді.
 */
class TelegramService
{
    /** Байланыстыру сілтемесінің жарамдылық мерзімі, минут. */
    public const LINK_MINUTES = 15;

    private const USERNAME = 'telegram_bot_username';

    private const WEBHOOK_AT = 'telegram_webhook_at';

    public function __construct(private readonly TelegramClient $client) {}

    /** Бот хабар жібере ала ма: токен бар және «Ботты қосу» сәтті өткен. */
    public function ready(): bool
    {
        return $this->client->configured() && $this->username() !== null;
    }

    public function username(): ?string
    {
        return Setting::text(self::USERNAME);
    }

    /** @return array{token_set: bool, username: ?string, webhook_at: ?string} */
    public function status(): array
    {
        return ['token_set' => $this->client->configured(), 'username' => $this->username(), 'webhook_at' => Setting::text(self::WEBHOOK_AT)];
    }

    /** Telegram сұраныстарын тексеретін құпия: `APP_KEY`-ден туындайды, еш жерде сақталмайды. */
    public function webhookSecret(): string
    {
        return hash_hmac('sha256', 'qatysu-telegram-webhook', (string) config('app.key'));
    }

    /**
     * Токенді тексереді және Telegram-ға сайттың мекенжайын тіркейді.
     *
     * @throws TelegramException
     */
    public function setup(): void
    {
        $me = $this->client->getMe();
        $this->client->setWebhook(rtrim((string) config('app.url'), '/').'/api/telegram/webhook', $this->webhookSecret());
        Setting::putText(self::USERNAME, $me['username']);
        Setting::putText(self::WEBHOOK_AT, now()->toIso8601String());
    }

    /**
     * Бір реттік байланыстыру сілтемесі. Бұрынғы сілтеме жарамсыз болады.
     *
     * @return array{url: string, expires_at: string}
     */
    public function createLink(User $user): array
    {
        $token = Str::random(40);
        $expires = now()->addMinutes(self::LINK_MINUTES);
        $user->forceFill(['telegram_link_hash' => hash('sha256', $token), 'telegram_link_expires_at' => $expires])->save();

        return ['url' => 'https://t.me/'.$this->username().'?start='.$token, 'expires_at' => $expires->toIso8601String()];
    }

    public function unlink(User $user): void
    {
        $user->forceFill(['telegram_chat_id' => null, 'telegram_linked_at' => null, 'telegram_link_hash' => null, 'telegram_link_expires_at' => null])->save();
    }

    /**
     * Telegram-нан келген бір жаңарту.
     *
     * @param  array<string, mixed>  $update
     */
    public function handleUpdate(array $update): void
    {
        $message = $update['message'] ?? null;
        // Топтық чатта бот жауап бермейді: хабарлар тек жеке чатқа жіберіледі.
        if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || ! isset($message['chat']['id'])) {
            return;
        }
        $chatId = (string) $message['chat']['id'];
        $text = trim((string) ($message['text'] ?? ''));
        $linked = User::query()->where('telegram_chat_id', $chatId)->first();

        if (preg_match('/^\/start(?:@\w+)?\s+([A-Za-z0-9_-]{20,64})$/', $text, $m) === 1) {
            $user = User::query()->where('telegram_link_hash', hash('sha256', $m[1]))->where('is_active', true)->first();
            if ($user === null || $user->telegram_link_expires_at === null || $user->telegram_link_expires_at->isPast()) {
                $this->reply($chatId, 'link_invalid', $linked?->locale->value ?? 'kk');

                return;
            }
            // Бір Telegram аккаунты бір пайдаланушыға ғана байланады.
            if ($linked !== null && $linked->id !== $user->id) {
                $this->unlink($linked);
            }
            $user->forceFill([
                'telegram_chat_id' => $chatId, 'telegram_linked_at' => now(), 'telegram_link_hash' => null, 'telegram_link_expires_at' => null,
            ])->save();
            $this->reply($chatId, 'linked', $user->locale->value, ['name' => $user->full_name]);

            return;
        }

        if (preg_match('/^\/stop(?:@\w+)?$/', $text) === 1) {
            if ($linked !== null) {
                $this->unlink($linked);
            }
            $this->reply($chatId, 'stopped', $linked?->locale->value ?? 'kk');

            return;
        }

        $this->reply($chatId, $linked === null ? 'help_unlinked' : 'help_linked', $linked?->locale->value ?? 'kk');
    }

    /** @param  array<string, string>  $replace */
    private function reply(string $chatId, string $key, string $locale, array $replace = []): void
    {
        try {
            $this->client->sendMessage($chatId, __('bot.'.$key, $replace + ['site' => rtrim((string) config('app.url'), '/')], $locale));
        } catch (Throwable $e) {
            // Жауап жіберілмесе де байланыстыру орындалды; токен журналға түспейді (клиент оны қатеге қоспайды).
            Log::warning('Telegram-ға жауап жіберілмеді: '.$e->getMessage());
        }
    }
}
