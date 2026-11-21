<?php

namespace App\Services;

use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Support\KazakhSort;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Оқушының сыныпқа тіркелуі. Ереже: оқушының бір уақытта бір ғана ашық тіркелуі болады,
 * ал өткен кезеңдер өзгермейді (ескі есептер бұзылмауы үшін).
 * Бизнес-ереже бұзылса, машина оқитын кодпен `DomainException` лақтырылады.
 */
class EnrollmentService
{
    public function enroll(Student $student, SchoolClass $class, CarbonImmutable $date): StudentEnrollment
    {
        $this->assertClassOpen($class);

        return DB::transaction(function () use ($student, $class, $date): StudentEnrollment {
            if ($this->current($student, lock: true) !== null) {
                throw new DomainException('already_enrolled');
            }
            $student->forceFill(['is_active' => true])->save();

            return StudentEnrollment::query()->create([
                'student_id' => $student->id,
                'class_id' => $class->id,
                'start_date' => $date->toDateString(),
            ]);
        });
    }

    /** Оқушы `$date` күнінен бастап жаңа сыныпта; оған дейінгі күндер ескі сыныпта қалады. */
    public function transfer(Student $student, SchoolClass $class, CarbonImmutable $date): StudentEnrollment
    {
        $this->assertClassOpen($class);

        return DB::transaction(function () use ($student, $class, $date): StudentEnrollment {
            $current = $this->current($student, lock: true) ?? throw new DomainException('not_enrolled');
            if ($current->class_id === $class->id) {
                throw new DomainException('same_class');
            }
            $start = CarbonImmutable::parse($current->start_date);
            if ($date->lt($start)) {
                throw new DomainException('date_before_enrollment');
            }
            if ($date->eq($start)) {
                // Сол күні-ақ түзетілген қате: бір күндік бос кезең қалдырмай, сыныбы ауыстырылады.
                $current->forceFill(['class_id' => $class->id])->save();

                return $current;
            }

            $current->forceFill(['end_date' => $date->subDay()->toDateString()])->save();

            return StudentEnrollment::query()->create([
                'student_id' => $student->id,
                'class_id' => $class->id,
                'start_date' => $date->toDateString(),
            ]);
        });
    }

    /** `$lastDay` — оқушының мектептегі соңғы күні. Жазбалары мен тарихы сақталады. */
    public function withdraw(Student $student, CarbonImmutable $lastDay): void
    {
        DB::transaction(function () use ($student, $lastDay): void {
            $current = $this->current($student, lock: true) ?? throw new DomainException('not_enrolled');
            if ($lastDay->lt(CarbonImmutable::parse($current->start_date))) {
                throw new DomainException('date_before_enrollment');
            }
            $current->forceFill(['end_date' => $lastDay->toDateString()])->save();
            $student->forceFill(['is_active' => false])->save();
        });
    }

    public function current(Student $student, bool $lock = false): ?StudentEnrollment
    {
        $query = StudentEnrollment::query()->where('student_id', $student->id)->whereNull('end_date');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /**
     * Көрсетілген күнгі сынып тізімі, қазақ әліпбиі ретімен (журналдағыдай).
     *
     * @return list<Student>
     */
    public function roster(SchoolClass $class, CarbonImmutable $date): array
    {
        $students = Student::query()
            ->whereHas('enrollments', fn ($q) => $q->where('class_id', $class->id)->activeOn($date))
            ->get();

        return KazakhSort::sort($students, static fn (Student $s): array => [
            $s->last_name, $s->first_name, (string) $s->patronymic,
        ]);
    }

    private function assertClassOpen(SchoolClass $class): void
    {
        if (! $class->is_active) {
            throw new DomainException('class_closed');
        }
    }
}
