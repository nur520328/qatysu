<?php

namespace App\Http\Middleware;

use App\Models\DutySession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Кезекші маршруттары: сеанста жарамды кезекші кіруі болуы тиіс. Код жойылса не смена бітсе,
 * планшет келесі сұраныста шығарылады.
 */
class EnsureDutySession
{
    public const SESSION_KEY = 'duty_session_id';

    /** `sync` режимі: смена біткеннен кейін де, сол күні, жіберілмей қалған аралауды қабылдайды. */
    public function handle(Request $request, Closure $next, string $mode = 'live'): Response
    {
        $id = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        $session = $id === null ? null : DutySession::query()->with('code')->find($id);

        if ($session === null) {
            return response()->json(['code' => 'duty_unauthenticated'], 401);
        }
        $allowed = $mode === 'sync' ? $session->code->acceptsLateSync() : $session->code->isUsableNow();
        if ($session->ended_at !== null || ! $allowed) {
            // Сеанс cookie-сі әдейі өшірілмейді: смена біткен соң да планшет жіберілмеген деректі жіберуі мүмкін.

            return response()->json(['code' => 'duty_code_ended'], 401);
        }

        // Завуч «планшет қашан соңғы рет байланысқа шықты» дегенді көреді; минутына бір реттен жиі жазылмайды.
        if ($session->last_seen_at->lt(now()->subMinute())) {
            $session->update(['last_seen_at' => now()]);
        }
        $request->attributes->set('duty_session', $session);

        return $next($request);
    }
}
