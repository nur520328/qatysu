<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Кіріп тұрған аккаунт өшірілсе, оның ашық сеансы келесі сұраныста жабылады
 * және бір реттік парольмен кірген адам оны ауыстырмайынша жұмыс беттеріне өтпейді.
 */
class EnsureActiveAccount
{
    /**
     * Шыққаннан кейін шақырылады. `auth:sanctum` пайдаланушыны сұраныс бойы өз жадында
     * ұстайды; тазаламаса, сұраныс соңында жазылатын жаңа (бос) сеанс жолы дерекқорға
     * сол пайдаланушының атымен түседі де, «осы адамның сеанстары» есебі бұзылады.
     */
    public static function forgetResolvedUser(): void
    {
        Auth::forgetGuards();
        app()->forgetInstance('auth.driver');
    }

    public function handle(Request $request, Closure $next, string $mode = 'strict'): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }
            self::forgetResolvedUser();

            return response()->json(['code' => 'unauthenticated'], 401);
        }

        // `auth` режимі кіру маршруттарына арналған: онда пароль ауыстыруға рұқсат керек.
        if ($mode === 'strict' && $user !== null && $user->must_change_password) {
            return response()->json(['code' => 'password_change_required'], 403);
        }

        return $next($request);
    }
}
