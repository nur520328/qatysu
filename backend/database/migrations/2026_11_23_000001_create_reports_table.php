<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Бекітілген есептер (сол сәттегі сурет). docs/кезең-8-жоспар.md, 6 және 8-шешім. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16); // weekly, monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('generated_at');
            // Есептің толық дерегі (оқушылардың аты-жөнімен): тілге тәуелсіз, файл сұраған сәтте жасалады.
            $table->longText('data');
            $table->timestamps();
            $table->unique(['type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
