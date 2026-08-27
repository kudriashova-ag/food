<?php

namespace App\Services\School;

use App\Models\SchoolClass;
use App\Models\Student;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Переведення на новий навчальний рік (ТЗ, п. 3.3).
 *
 * 1-А стає 2-А, випускники (після 11 класу) деактивуються.
 * Історія замовлень не чіпається: вона прив'язана до позицій, а не до класу.
 */
class AcademicYearService
{
    public const GRADUATION_GRADE = 11;

    /**
     * @return array{promoted: int, graduated: int, classes: int}
     */
    public function promote(int $fromYear): array
    {
        $classes = SchoolClass::query()
            ->where('academic_year', $fromYear)
            ->where('is_active', true)
            ->orderBy('grade')
            ->get();

        $promoted = 0;
        $graduated = 0;
        $created = 0;

        DB::transaction(function () use ($classes, $fromYear, &$promoted, &$graduated, &$created): void {
            foreach ($classes as $class) {
                $students = Student::query()->where('school_class_id', $class->id)->get();

                if ($class->grade >= self::GRADUATION_GRADE) {
                    $graduated += $this->graduate($students);

                    continue;
                }

                $nextClass = SchoolClass::query()->firstOrCreate([
                    'academic_year' => $fromYear + 1,
                    'grade' => $class->grade + 1,
                    'letter' => $class->letter,
                ], ['is_active' => true]);

                if ($nextClass->wasRecentlyCreated) {
                    $created++;
                }

                $promoted += $this->moveTo($students, $nextClass);
            }

            // Класи минулого року лишаються в базі, але вже неактивні.
            SchoolClass::query()->where('academic_year', $fromYear)->update(['is_active' => false]);
        });

        return ['promoted' => $promoted, 'graduated' => $graduated, 'classes' => $created];
    }

    /** @param Collection<int, Student> $students */
    private function moveTo(Collection $students, SchoolClass $class): int
    {
        foreach ($students as $student) {
            $student->update(['school_class_id' => $class->id]);
        }

        return $students->count();
    }

    /** @param Collection<int, Student> $students */
    private function graduate(Collection $students): int
    {
        foreach ($students as $student) {
            $student->update(['is_active' => false]);
            $student->user?->update(['is_active' => false]);
        }

        return $students->count();
    }
}
