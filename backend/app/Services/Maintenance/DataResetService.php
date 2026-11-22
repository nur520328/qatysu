<?php

namespace App\Services\Maintenance;

use App\Enums\Role;
use Illuminate\Support\Facades\DB;

/**
 * Сынақ кезеңінен кейін мектеп дерегін тазалайды: оқушылар, сыныптар, мұғалімдер, қатысу жазбалары.
 * Завуч аккаунттары, баптау, оқу жылы мен күнтізбе қалады.
 *
 * Жаңа кесте қосылғанда оны `CLEARED` не `KEPT` тізіміне жазу міндетті: әйтпесе
 * `DataResetTest::test_every_table_is_either_cleared_or_kept` құлайды.
 */
class DataResetService
{
    /** Өшірілетін кестелер, сыртқы кілттерге сай ретпен (тәуелдісі алда). */
    public const CLEARED = [
        'attendance_audit',
        'attendance_round_conflicts',
        'attendance_records',
        'attendance_rounds',
        'duty_sessions',
        'duty_codes',
        'reports',
        'student_enrollments',
        'students',
        'classes',
    ];

    /** Тиіспейтін кестелер. `users` пен `sessions` жартылай тазаланады: завуч аккаунттары қалады. */
    public const KEPT = [
        'academic_years', 'calendar_days', 'settings', 'migrations',
        'users', 'sessions',
        'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
    ];

    /**
     * Не өшірілетінінің саны (ештеңе өзгертпейді).
     *
     * @return array{students: int, classes: int, teachers: int, records: int, rounds: int, days: int, admins: int}
     */
    public function counts(): array
    {
        return [
            'students' => DB::table('students')->count(),
            'classes' => DB::table('classes')->count(),
            'teachers' => DB::table('users')->where('role', '!=', Role::Admin->value)->count(),
            'records' => DB::table('attendance_records')->count(),
            'rounds' => DB::table('attendance_rounds')->count(),
            // Неше түрлі күнге қатысу белгіленген: сынақ дерегін нақты жұмыстан ажырататын белгі.
            'days' => DB::table('attendance_rounds')->distinct()->count('date'),
            'admins' => DB::table('users')->where('role', Role::Admin->value)->count(),
        ];
    }

    /** Мектеп дерегін өшіреді. Алдын ала сақтық көшірме жасау шақырушының міндеті. */
    public function clear(): void
    {
        DB::transaction(function (): void {
            foreach (self::CLEARED as $table) {
                DB::table($table)->delete();
            }
            $admins = DB::table('users')->where('role', Role::Admin->value)->pluck('id');
            // Мұғалімдердің және планшеттердің ашық сеанстары жабылады; завуч жүйеде қалады.
            DB::table('sessions')->where(fn ($q) => $q->whereNull('user_id')->orWhereNotIn('user_id', $admins))->delete();
            DB::table('users')->whereNotIn('id', $admins)->delete();
        });
    }
}
