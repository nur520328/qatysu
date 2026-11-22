<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Telegram\TelegramException;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Аккаунтты ботпен байланыстыру (кез келген кірген адам) және ботты қосу (завуч). Келісім: docs/api.md. */
class LinkController extends Controller
{
    public function __construct(private readonly TelegramService $telegram) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->state($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->telegram->ready()) {
            return $this->error('telegram_not_ready', 409);
        }

        return response()->json($this->telegram->createLink($request->user()), 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->telegram->unlink($request->user());

        return response()->json($this->state($request->user()));
    }

    public function adminShow(): JsonResponse
    {
        return response()->json($this->adminState());
    }

    public function adminSetup(): JsonResponse
    {
        try {
            $this->telegram->setup();
        } catch (TelegramException $e) {
            // token_missing | unreachable | Telegram-ның өз жауабы (мысалы, токен қате: 401 Unauthorized).
            $code = match (true) {
                $e->getMessage() === 'token_missing' => 'telegram_token_missing',
                $e->getMessage() === 'unreachable' => 'telegram_unreachable',
                $e->errorCode === 401 || $e->errorCode === 404 => 'telegram_token_invalid',
                default => 'telegram_rejected',
            };

            return response()->json(['code' => $code, 'detail' => $code === 'telegram_rejected' ? mb_substr($e->getMessage(), 0, 200) : null], 422);
        }

        return response()->json($this->adminState());
    }

    /** @return array<string, mixed> */
    private function state(User $user): array
    {
        return [
            'ready' => $this->telegram->ready(),
            'bot_username' => $this->telegram->username(),
            'linked' => $user->telegram_chat_id !== null,
            'linked_at' => $user->telegram_linked_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function adminState(): array
    {
        $since = now()->subDay();

        return $this->telegram->status() + [
            'linked_users' => User::query()->whereNotNull('telegram_chat_id')->where('is_active', true)->count(),
            'active_users' => User::query()->where('is_active', true)->count(),
            'sent_24h' => NotificationLog::query()->where('status', NotificationLog::SENT)->where('sent_at', '>=', $since)->count(),
            'failed_24h' => NotificationLog::query()->whereIn('status', [NotificationLog::FAILED, NotificationLog::EXPIRED])->where('updated_at', '>=', $since)->count(),
        ];
    }
}
