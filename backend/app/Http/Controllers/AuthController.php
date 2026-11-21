<?php

namespace App\Http\Controllers;

use App\Enums\Locale;
use App\Http\Middleware\EnsureActiveAccount;
use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/** `docs/api.md` файлындағы «Кіру» бөлімін жүзеге асырады. */
class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const LOCKOUT_SECONDS = 300;

    public function me(Request $request): JsonResponse
    {
        return $this->userResponse($request->user());
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:60'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $login = User::normalizeLogin($data['login']);
        $throttleKey = 'login:'.sha1($login).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            return $this->error('too_many_attempts', 429);
        }

        // Қате логин, қате пароль және өшірілген аккаунт бірдей жауап алады:
        // логиннің бар-жоғы сырттан анықталмауы тиіс.
        $credentials = ['login' => $login, 'password' => $data['password'], 'is_active' => true];
        if (! Auth::guard('web')->attempt($credentials)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            return $this->error('invalid_credentials', 422);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        return $this->userResponse($request->user());
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        EnsureActiveAccount::forgetResolvedUser();

        return response()->noContent();
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'max:255'],
            'new_password' => ['required', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return $this->error('wrong_current_password', 422);
        }
        if (! PasswordPolicy::isStrong($data['new_password'])) {
            return $this->error('weak_password', 422);
        }
        if (Hash::check($data['new_password'], $user->password)) {
            return $this->error('same_password', 422);
        }

        $user->forceFill([
            'password' => $data['new_password'],
            'must_change_password' => false,
        ])->save();

        // Пароль ауысқанда осы аккаунттың басқа құрылғылардағы сеанстары жабылады.
        $request->session()->regenerate();
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        return $this->userResponse($user);
    }

    public function setLocale(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(Locale::values())],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['locale' => $data['locale']])->save();

        return $this->userResponse($user);
    }

    private function userResponse(User $user): JsonResponse
    {
        return response()->json(['user' => $user->toApi()]);
    }
}
