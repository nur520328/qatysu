<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Telegram серверінен келетін хабарлар. Кіру жоқ: сұраныс құпия тақырыппен тексеріледі. */
class WebhookController extends Controller
{
    public function __invoke(Request $request, TelegramService $telegram): JsonResponse
    {
        $given = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');
        if (! $telegram->status()['token_set'] || ! hash_equals($telegram->webhookSecret(), $given)) {
            return $this->error('forbidden', 403);
        }
        try {
            $telegram->handleUpdate($request->json()->all());
        } catch (Throwable $e) {
            // Қате жауап қайтарылса, Telegram сол хабарды қайта-қайта жібере береді.
            Log::error('Telegram хабарын өңдеу қатесі: '.$e->getMessage());
        }

        return response()->json(['ok' => true]);
    }
}
