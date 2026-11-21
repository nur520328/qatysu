<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Келісім: docs/api.md. Алдымен құжат өзгереді, содан кейін осы файл.

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'account:auth'])->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
        Route::post('password', [AuthController::class, 'changePassword']);
        Route::post('locale', [AuthController::class, 'setLocale']);
    });
});

// Жұмыс маршруттары осы топтардың ішіне қосылады (3-кезеңнен бастап).
// `account` бір реттік парольді ауыстырмаған адамды өткізбейді.
Route::middleware(['auth:sanctum', 'account'])->group(function () {
    Route::middleware('role:admin')->prefix('admin')->group(function () {
        //
    });

    Route::middleware('role:admin,teacher')->prefix('teacher')->group(function () {
        //
    });
});
