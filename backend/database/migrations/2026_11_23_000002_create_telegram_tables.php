<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Telegram бот: байланыстыру коды және хабарлар кезегі. docs/кезең-10-жоспар.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Бір реттік байланыстыру кодының хэші (ашық түрде сақталмайды).
            $table->string('telegram_link_hash', 64)->nullable()->unique()->after('telegram_chat_id');
            $table->dateTime('telegram_link_expires_at')->nullable()->after('telegram_link_hash');
            $table->dateTime('telegram_linked_at')->nullable()->after('telegram_link_expires_at');
        });

        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32);
            // Бір оқиға туралы бір адамға бір-ақ хабар: (user_id, dedupe_key) бірегей.
            $table->string('dedupe_key', 100);
            // Дайын мәтін, алушының тілінде. Оқушының аты-жөні де, себебі де болмайды.
            $table->text('text');
            // Бекітілген есеп туралы хабарға аты-жөнсіз Excel тіркеледі (жіберер сәтте жасалады).
            $table->foreignId('report_id')->nullable()->constrained('reports')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error', 255)->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'dedupe_key']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['telegram_link_hash', 'telegram_link_expires_at', 'telegram_linked_at']);
        });
    }
};
