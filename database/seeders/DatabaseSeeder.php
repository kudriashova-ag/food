<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Allergen;
use App\Models\DeadlineRule;
use App\Models\SchoolClass;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /** Рік початку навчального року: 2026 = 2026/27. */
    private const ACADEMIC_YEAR = 2026;

    public function run(): void
    {
        $this->seedAdmin();
        $this->seedClasses();
        $this->seedAllergens();
        $this->seedSuppliers();
    }

    private function seedAdmin(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@school.local'],
            [
                'name' => 'Адміністратор школи',
                'password' => 'secret',
                'role' => UserRole::Admin,
                'is_active' => true,
            ],
        );
    }

    private function seedClasses(): void
    {
        foreach (range(1, 11) as $grade) {
            foreach (['А', 'Б'] as $letter) {
                SchoolClass::query()->updateOrCreate(
                    ['academic_year' => self::ACADEMIC_YEAR, 'grade' => $grade, 'letter' => $letter],
                    ['is_active' => true],
                );
            }
        }
    }

    private function seedAllergens(): void
    {
        $allergens = ['Глютен', 'Лактоза', 'Яйця', 'Горіхи', 'Риба', 'Соя', 'Мед'];

        foreach ($allergens as $name) {
            Allergen::query()->updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            );
        }
    }

    private function seedSuppliers(): void
    {
        $suppliers = [
            ['name' => 'Смачно', 'slug' => 'smachno', 'email' => 'smachno@school.local'],
            ['name' => 'Домашня кухня', 'slug' => 'domashnya-kuhnya', 'email' => 'domashnya@school.local'],
        ];

        foreach ($suppliers as $sort => $data) {
            $supplier = Supplier::query()->updateOrCreate(
                ['slug' => $data['slug']],
                [
                    'name' => $data['name'],
                    'is_visible' => true,
                    'sort' => $sort,
                ],
            );

            User::query()->updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => 'secret',
                    'role' => UserRole::Supplier,
                    'supplier_id' => $supplier->id,
                    'is_active' => true,
                ],
            );

            // Стартове правило: замовлення й скасування — до 09:00 попереднього дня, пн–пт.
            foreach (range(1, 5) as $weekday) {
                DeadlineRule::query()->updateOrCreate(
                    ['supplier_id' => $supplier->id, 'weekday' => $weekday],
                    [
                        'order_offset_days' => 1,
                        'order_time' => '09:00:00',
                        'cancel_offset_days' => 1,
                        'cancel_time' => '09:00:00',
                    ],
                );
            }
        }
    }
}
