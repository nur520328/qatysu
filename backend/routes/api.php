<?php

use App\Http\Controllers\Admin\AcademicYearController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\SchoolClassController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\TeacherController;
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
        Route::get('academic-years', [AcademicYearController::class, 'index']);
        Route::post('academic-years', [AcademicYearController::class, 'store']);
        Route::post('academic-years/{year}/make-current', [AcademicYearController::class, 'makeCurrent']);
        Route::get('academic-years/{year}/calendar', [AcademicYearController::class, 'calendar']);
        Route::put('academic-years/{year}/calendar', [AcademicYearController::class, 'updateCalendar']);

        Route::get('classes', [SchoolClassController::class, 'index']);
        Route::post('classes', [SchoolClassController::class, 'store']);
        Route::patch('classes/{class}', [SchoolClassController::class, 'update']);

        Route::get('classes/{class}/students', [StudentController::class, 'index']);
        Route::post('classes/{class}/students', [StudentController::class, 'store']);
        Route::patch('students/{student}', [StudentController::class, 'update']);
        Route::post('students/{student}/transfer', [StudentController::class, 'transfer']);
        Route::post('students/{student}/withdraw', [StudentController::class, 'withdraw']);

        Route::post('import/students', [ImportController::class, 'students']);
        Route::post('import/teachers', [ImportController::class, 'teachers']);

        Route::get('users', [TeacherController::class, 'index']);
        Route::post('users', [TeacherController::class, 'store']);
        Route::patch('users/{user}', [TeacherController::class, 'update']);
        Route::post('users/{user}/reset-password', [TeacherController::class, 'resetPassword']);

        Route::get('settings', [SettingController::class, 'index']);
        Route::patch('settings', [SettingController::class, 'update']);
    });

    Route::middleware('role:admin,teacher')->prefix('teacher')->group(function () {
        //
    });
});
