<?php

namespace App\Services\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Telegram Bot API-мен сөйлесетін жалғыз орын. Токен тек конфигтен (`.env`) алынады және ешқашан
 * қате мәтініне не журналға түспейді.
 */
class TelegramClient
{
    public function configured(): bool
    {
        return $this->token() !== '';
    }

    /** @return array{id: int, username: string} */
    public function getMe(): array
    {
        $me = $this->call('getMe');

        return ['id' => (int) $me['id'], 'username' => (string) ($me['username'] ?? '')];
    }

    public function setWebhook(string $url, string $secret): void
    {
        $this->call('setWebhook', [
            'url' => $url, 'secret_token' => $secret, 'allowed_updates' => ['message'], 'drop_pending_updates' => true,
        ]);
    }

    public function sendMessage(string $chatId, string $text): void
    {
        $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true]);
    }

    public function sendDocument(string $chatId, string $filename, string $content, string $caption): void
    {
        $this->call('sendDocument', ['chat_id' => $chatId, 'caption' => $caption], [$filename, $content]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array{string, string}|null  $document  [файл атауы, мазмұны]
     * @return array<string, mixed>
     */
    private function call(string $method, array $params = [], ?array $document = null): array
    {
        if (! $this->configured()) {
            throw new TelegramException('token_missing');
        }
        $url = rtrim((string) config('services.telegram.api'), '/').'/bot'.$this->token().'/'.$method;
        try {
            $request = Http::timeout(15)->connectTimeout(8)->acceptJson();
            $response = $document === null
                ? $request->asJson()->post($url, $params)
                : $request->attach('document', $document[1], $document[0])->post($url, $params);
        } catch (ConnectionException) {
            // Қате мәтінінде токені бар мекенжай болуы мүмкін: оны сыртқа шығармаймыз.
            throw new TelegramException('unreachable');
        }

        $body = $response->json();
        if (! is_array($body) || ($body['ok'] ?? false) !== true) {
            throw new TelegramException(
                is_array($body) ? (string) ($body['description'] ?? 'error') : 'bad_response',
                is_array($body) ? (int) ($body['error_code'] ?? $response->status()) : $response->status(),
                is_array($body) && isset($body['parameters']['retry_after']) ? (int) $body['parameters']['retry_after'] : null,
            );
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }

    private function token(): string
    {
        return trim((string) config('services.telegram.token'));
    }
}
