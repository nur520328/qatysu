<?php

namespace App\Http\Controllers\Duty;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureDutySession;
use App\Models\DutySession;
use App\Services\Duty\DutyCodeService;
use App\Services\Duty\RoundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Кезекшінің кодпен кіруі. Келісім: docs/api.md, «Кезекші API». */
class SessionController extends Controller
{
    private const MAX_ATTEMPTS_PER_IP = 5;

    /** Бүкіл мектеп бойынша қате әрекеттер шегі: 6 цифрды теріп табуға жол бермейді. */
    public const MAX_FAILURES_GLOBAL = 40;

    public const GLOBAL_KEY = 'duty-login-failures';

    private const WINDOW_SECONDS = 300;

    public function __construct(private readonly DutyCodeService $codes, private readonly RoundService $rounds) {}

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']]);

        // Мұғалім не завуч кіріп тұрған браузерде кезекші сеансы ашылмайды: екі рөл араласпауы тиіс.
        if (Auth::guard('web')->check()) {
            return $this->error('signed_in_as_user', 409);
        }

        $ipKey = 'duty-login:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipKey, self::MAX_ATTEMPTS_PER_IP) || RateLimiter::tooManyAttempts(self::GLOBAL_KEY, self::MAX_FAILURES_GLOBAL)) {
            return $this->error('too_many_attempts', 429);
        }

        $code = $this->codes->findUsable($data['code']);
        if ($code === null) {
            RateLimiter::hit($ipKey, self::WINDOW_SECONDS);
            RateLimiter::hit(self::GLOBAL_KEY, self::WINDOW_SECONDS);

            return $this->error('invalid_duty_code', 422);
        }
        RateLimiter::clear($ipKey);

        $session = DB::transaction(function () use ($code, $request): DutySession {
            $number = (int) DutySession::query()->where('duty_code_id', $code->id)->lockForUpdate()->max('device_number') + 1;

            return DutySession::query()->create([
                'duty_code_id' => $code->id, 'device_number' => $number, 'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255), 'last_seen_at' => now(),
            ]);
        });

        $request->session()->regenerate();
        $request->session()->put(EnsureDutySession::SESSION_KEY, $session->id);

        return $this->state($session->load('code'));
    }

    public function show(Request $request): JsonResponse
    {
        return $this->state($request->attributes->get('duty_session'));
    }

    public function logout(Request $request): Response
    {
        $id = $request->session()->pull(EnsureDutySession::SESSION_KEY);
        if ($id !== null) {
            DutySession::query()->whereKey($id)->whereNull('ended_at')->update(['ended_at' => now()]);
        }
        $request->session()->regenerate();

        return response()->noContent();
    }

    private function state(DutySession $session): JsonResponse
    {
        $code = $session->code;

        return response()->json([
            'date' => $code->date->toDateString(),
            'shift' => $code->shift->value,
            'expires_at' => $code->expires_at->toIso8601String(),
            'server_time' => now()->toIso8601String(),
            'device_number' => $session->device_number,
            'classes' => $this->rounds->classes($code),
        ]);
    }
}
