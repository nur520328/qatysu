<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Кезекші коды және қатысу кестелері. Сипаттамасы: docs/жобалық-құжат.md 7-бөлім, docs/кезең-4-жоспар.md. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duty_codes', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('shift', 16);
            $table->string('code_hash');
            // Завуч кодты күні бойы қайта көре алуы үшін (тапсырыс берушінің шешімі, 2026-11-21) код
            // қосымша кілтімен шифрланып сақталады. Смена біткенде не код жойылғанда бұл өріс тазаланады.
            $table->text('code_encrypted')->nullable();
            $table->foreignId('issued_by')->constrained('users');
            $table->dateTime('expires_at');
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['date', 'shift']);
        });

        Schema::create('duty_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duty_code_id')->constrained();
            // Завуч пен тарих үшін: «1-планшет», «2-планшет» (сол код бойынша кіру реті).
            $table->unsignedSmallInteger('device_number');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('last_seen_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes');
            $table->date('date');
            $table->string('shift', 16);
            $table->foreignId('duty_code_id')->nullable()->constrained();
            $table->foreignId('duty_session_id')->nullable()->constrained();
            // Планшет берген бірегей нөмір: бір аралау қанша рет жіберілсе де бір-ақ рет жазылады.
            $table->uuid('client_uuid')->unique();
            $table->dateTime('client_time')->nullable();
            // Әр сақтаған сайын өседі: екі планшеттің бір-бірінің өзгерісін үнсіз басуына жол бермейді.
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            // Бір сыныпқа бір күнде бір ғана аралау; басқасы attendance_round_conflicts кестесіне түседі.
            $table->unique(['class_id', 'date']);
            $table->index('date');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('attendance_rounds');
            $table->foreignId('student_id')->constrained();
            $table->string('round_status', 16);
            // «Бар/жоқ» белгісін соңғы рет кім қойғаны: duty не staff. Staff қойса, кезекші өзгерте алмайды.
            $table->string('round_status_source', 16)->default('duty');
            $table->unsignedTinyInteger('arrived_lesson')->nullable();
            $table->unsignedTinyInteger('left_lesson')->nullable();
            $table->string('absence_reason', 16)->nullable();
            $table->string('leave_permission', 16)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['round_id', 'student_id']);
            $table->index('student_id');
        });

        Schema::create('attendance_audit', function (Blueprint $table) {
            $table->id();
            // Жазба ешқашан өшірілмейді, сондықтан сыртқы кілтте каскад жоқ.
            $table->foreignId('record_id')->constrained('attendance_records');
            $table->string('field', 32);
            $table->string('old_value', 500)->nullable();
            $table->string('new_value', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users');
            $table->foreignId('duty_session_id')->nullable()->constrained();
            $table->string('changed_role', 16);
            $table->dateTime('changed_at');
            $table->index('record_id');
        });

        Schema::create('attendance_round_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes');
            $table->date('date');
            $table->foreignId('duty_code_id')->nullable()->constrained();
            $table->foreignId('duty_session_id')->nullable()->constrained();
            $table->uuid('client_uuid')->unique();
            $table->dateTime('client_time')->nullable();
            // Сол планшет жіберген «жоқ» оқушылардың нөмірлері (JSON тізім).
            $table->json('absent_student_ids');
            $table->dateTime('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users');
            $table->string('resolution', 16)->nullable();
            $table->timestamps();
            $table->index(['date', 'resolved_at']);
        });
    }

    public function down(): void
    {
        foreach (['attendance_round_conflicts', 'attendance_audit', 'attendance_records', 'attendance_rounds', 'duty_sessions', 'duty_codes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
