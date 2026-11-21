<?php

use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsureRole;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Кіру cookie арқылы жүреді (бір домендегі SPA), токен браузерде сақталмайды.
        $middleware->statefulApi();
        $middleware->alias([
            'role' => EnsureRole::class,
            'account' => EnsureActiveAccount::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API қателері машина оқитын `code` қайтарады; мәтінді интерфейс аударады.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['code' => 'unauthenticated'], 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['code' => 'validation_failed', 'fields' => array_keys($e->errors())], 422);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($request->is('api/*')) {
                $codes = [403 => 'forbidden', 404 => 'not_found', 419 => 'csrf_mismatch', 429 => 'too_many_attempts'];

                return response()->json(['code' => $codes[$e->getStatusCode()] ?? 'unknown'], $e->getStatusCode());
            }
        });
    })->create();
