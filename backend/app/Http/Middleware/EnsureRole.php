<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Маршрутты көрсетілген рөлдерге ғана ашады: `role:admin` не `role:admin,teacher`.
 * Қолжетімділік осы жерде, серверде шешіледі; интерфейсте жасыру қорғаныс емес.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(static fn (string $role): Role => Role::from($role), $roles);
        $user = $request->user();

        if ($user === null || ! in_array($user->role, $allowed, true)) {
            return response()->json(['code' => 'forbidden'], 403);
        }

        return $next($request);
    }
}
