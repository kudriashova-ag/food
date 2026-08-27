<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Кілька учнів для розробки. У бою акаунти створює імпорт зі списку школи —
 * цей сідер потрібен лише щоб було під ким заходити локально.
 */
class DemoStudentsSeeder extends Seeder
{
    private const PASSWORD = 'secret';

    public function run(): void
    {
        $students = [
            ['login' => 'ivanenko.mariia', 'name' => 'Іваненко Марія', 'grade' => 5, 'letter' => 'А'],
            ['login' => 'petrenko.ivan', 'name' => 'Петренко Іван', 'grade' => 5, 'letter' => 'А'],
            ['login' => 'kovalenko.olha', 'name' => 'Коваленко Ольга', 'grade' => 7, 'letter' => 'Б'],
        ];

        foreach ($students as $data) {
            $class = SchoolClass::query()->firstOrCreate([
                'academic_year' => 2026,
                'grade' => $data['grade'],
                'letter' => $data['letter'],
            ]);

            $user = User::query()->updateOrCreate(
                ['login' => $data['login']],
                [
                    'name' => $data['name'],
                    'password' => self::PASSWORD,
                    'role' => UserRole::Student,
                    'is_active' => true,
                ],
            );

            Student::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'full_name' => $data['name'],
                    'school_class_id' => $class->id,
                    'is_active' => true,
                ],
            );
        }
    }
}
