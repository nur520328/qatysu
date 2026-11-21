<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Жобалық құжат, 7-бөлім: `users`. Жетекші құқығы осында емес,
        // `classes.homeroom_user_id` арқылы беріледі (3-кезең).
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('full_name', 150);
            // Логин кіші әріппен сақталады: кіруде бас-кіші әріп ажыратылмайды.
            $table->string('login', 60)->unique();
            $table->string('password');
            $table->string('role', 20);
            $table->string('locale', 5)->default('kk');
            // Бір реттік парольмен ашылған аккаунт оны алғаш кіргенде ауыстыруға міндетті.
            $table->boolean('must_change_password')->default(true);
            $table->boolean('is_active')->default(true);
            $table->string('telegram_chat_id', 32)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
