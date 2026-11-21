<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Смена біткеннен кейін серверге жеткен аралау (интернет болмаған). docs/кезең-5-жоспар.md, 6-шешім. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_rounds', function (Blueprint $table) {
            $table->boolean('received_late')->default(false)->after('revision');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_rounds', function (Blueprint $table) {
            $table->dropColumn('received_late');
        });
    }
};
