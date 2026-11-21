<?php

use App\Http\Controllers\Admin\AcademicYearController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\DutyCodeController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\RoundConflictController;
use App\Http\Controllers\Admin\SchoolClassController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\TeacherController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Duty\RoundController;
use App\Http\Controllers\Duty\SessionController;
use App\Http\Controllers\Homeroom\DayController;
use App\Http\Controllers\Teacher\AttendanceController;
use App\Http\Controllers\Teacher\HomeroomController;
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
        Route::post('classes/bulk', [SchoolClassController::class, 'bulk']);
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

        Route::get('duty-codes', [DutyCodeController::class, 'index']);
        Route::post('duty-codes', [DutyCodeController::class, 'store']);
        Route::delete('duty-codes/{code}', [DutyCodeController::class, 'destroy']);

        Route::get('round-conflicts', [RoundConflictController::class, 'index']);
        Route::post('round-conflicts/{conflict}/resolve', [RoundConflictController::class, 'resolve']);

        Route::get('backups', [BackupController::class, 'index']);
        Route::post('backups', [BackupController::class, 'store']);
        Route::get('backups/{name}/download', [BackupController::class, 'download']);

        Route::get('settings', [SettingController::class, 'index']);
        Route::patch('settings', [SettingController::class, 'update']);
    });

    Route::middleware('role:admin,teacher')->prefix('teacher')->group(function () {
        Route::get('homeroom', [HomeroomController::class, 'index']);

        // Пән мұғалімі: бүгінгі күн, кез келген сынып.
        Route::get('classes', [AttendanceController::class, 'classes']);
        Route::get('classes/{class}', [AttendanceController::class, 'show']);
        Route::get('students', [AttendanceController::class, 'search']);
        Route::post('students/{student}/arrival', [AttendanceController::class, 'arrive']);
        Route::delete('students/{student}/arrival', [AttendanceController::class, 'undoArrive']);
        Route::post('students/{student}/leave', [AttendanceController::class, 'leave']);
        Route::delete('students/{student}/leave', [AttendanceController::class, 'undoLeave']);
    });

    // Сынып жетекшісі (өз сыныбы) және завуч (кез келген сынып). Кімге не рұқсат екенін контроллер тексереді.
    Route::middleware('role:admin,teacher')->prefix('homeroom')->group(function () {
        Route::get('pending', [DayController::class, 'pending']);
        Route::get('classes/{class}/days/{date}', [DayController::class, 'show']);
        Route::patch('classes/{class}/days/{date}/students/{student}', [DayController::class, 'update']);
        Route::get('classes/{class}/days/{date}/students/{student}/history', [DayController::class, 'history']);
    });
});

// Кезекші оқушы: аккаунты жоқ, күндік кодпен кіреді (docs/кезең-4-жоспар.md).
Route::prefix('duty')->group(function () {
    Route::post('login', [SessionController::class, 'login']);
    Route::post('logout', [SessionController::class, 'logout']);

    Route::middleware('duty')->group(function () {
        Route::get('session', [SessionController::class, 'show']);
        Route::get('rosters', [RoundController::class, 'index']);
        Route::get('classes/{class}', [RoundController::class, 'show']);
    });
    // Смена кезінде интернетсіз белгіленген дерек сол күннің соңына дейін қабылданады.
    Route::middleware('duty:sync')->put('classes/{class}/round', [RoundController::class, 'save']);
});
