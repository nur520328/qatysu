<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Жобалық құжат, 7-бөлім: анықтамалық кестелер (3-кезең).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('is_current')->default(false);
            $table->timestamps();
        });

        // Оқу жылының әр күніне бір жол. Оқу күні емес күндері (демалыс, каникул, мереке)
        // аралау күтілмейді және есепке кірмейді.
        Schema::create('calendar_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->date('date')->unique();
            $table->boolean('is_school_day');
            $table->string('note', 100)->nullable();
        });

        Schema::create('classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('grade');
            // Литера реттік нөмірден есептелмейді: мектепте қатар үзік болуы мүмкін (10-да Д жоқ).
            $table->string('letter', 2);
            $table->string('shift', 12);
            $table->string('language', 2)->default('kk');
            $table->foreignId('homeroom_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['academic_year_id', 'grade', 'letter']);
        });

        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('last_name', 60);
            $table->string('first_name', 60);
            $table->string('patronymic', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
        });

        // Оқушының қай күннен қай күнге дейін қай сыныпта болғаны. Сынып ауысса, ескі жол
        // жабылып, жаңасы ашылады: өткен күндердің тізімі мен есептері өзгермейді.
        Schema::create('student_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->date('start_date');
            // Сыныптағы соңғы күн (қоса есептегенде). Бос болса, оқушы әлі осы сыныпта.
            $table->date('end_date')->nullable();
            $table->timestamps();

            $table->index(['class_id', 'start_date', 'end_date']);
            $table->index(['student_id', 'end_date']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('students');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('calendar_days');
        Schema::dropIfExists('academic_years');
    }
};
