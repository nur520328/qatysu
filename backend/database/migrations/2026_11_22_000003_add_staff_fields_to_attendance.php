<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Мұғалім мен жетекшінің тіркеулері үшін. docs/кезең-6-жоспар.md, 6 және 7-шешім. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_rounds', function (Blueprint $table) {
            // false: күннің жазбасын мұғалім не жетекші ашқан, кезекші сыныпты әлі аралаған жоқ.
            $table->boolean('duty_done')->default(true)->after('received_late');
        });
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('arrived_by')->nullable()->after('arrived_lesson')->constrained('users');
            $table->foreignId('left_by')->nullable()->after('left_lesson')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('arrived_by');
            $table->dropConstrainedForeignId('left_by');
        });
        Schema::table('attendance_rounds', function (Blueprint $table) {
            $table->dropColumn('duty_done');
        });
    }
};
