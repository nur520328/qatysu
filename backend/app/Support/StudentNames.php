<?php

namespace App\Support;

use App\Models\Student;
use App\Models\StudentEnrollment;
use Carbon\CarbonImmutable;

/**
 * Оқушының тізімде көрсетілетін аты: «Тегі Аты». Сол сыныпта тегі мен аты бірдей басқа оқушы болса,
 * әкесінің аты қосылады, әйтпесе екеуін ажырату мүмкін емес (2026-11-21 сынақта осындай жұп кездесті).
 */
class StudentNames
{
    /**
     * @param  iterable<Student>  $students
     * @param  array<int, int>  $classByStudent  оқушы нөмірі => сынып нөмірі
     * @return array<int, string> оқушы нөмірі => көрсетілетін аты
     */
    public static function labels(iterable $students, array $classByStudent, CarbonImmutable $date): array
    {
        $twins = [];
        $enrollments = StudentEnrollment::query()->whereIn('class_id', array_unique(array_values($classByStudent)))->activeOn($date)->with('student')->get();
        foreach ($enrollments as $enrollment) {
            $key = $enrollment->class_id.'|'.mb_strtolower($enrollment->student->last_name.' '.$enrollment->student->first_name);
            $twins[$key] = ($twins[$key] ?? 0) + 1;
        }

        $labels = [];
        foreach ($students as $student) {
            $name = trim($student->last_name.' '.$student->first_name);
            $key = ($classByStudent[$student->id] ?? 0).'|'.mb_strtolower($name);
            $labels[$student->id] = ($twins[$key] ?? 0) > 1 && $student->patronymic !== null ? $name.' '.$student->patronymic : $name;
        }

        return $labels;
    }
}
