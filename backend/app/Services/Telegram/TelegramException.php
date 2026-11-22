<?php

namespace App\Services\Telegram;

use RuntimeException;

/** Telegram сұранысы орындалмады. `errorCode` — Telegram қайтарған код (желі қатесінде 0). */
class TelegramException extends RuntimeException
{
    public function __construct(string $message, public readonly int $errorCode = 0, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message);
    }

    /** Пайдаланушы ботты бұғаттаған, чатты өшірген не әлі бастамаған: қайталаудың мәні жоқ. */
    public function recipientGone(): bool
    {
        return $this->errorCode === 403 || ($this->errorCode === 400 && str_contains(mb_strtolower($this->getMessage()), 'chat not found'));
    }
}
