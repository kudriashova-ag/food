<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Filament\Resources\OrderLines\Pages\ListOrderLines;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Кнопки «Експорт: учні» / «Експорт: вчителі» на сторінці замовлень в адмінці школи. */
class OrderExportActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Filament::setCurrentPanel('admin');

        $this->actingAs(User::create([
            'name' => 'Адміністратор',
            'login' => 'admin',
            'email' => 'admin@school.test',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]));
    }

    public function test_export_pupils_downloads_a_file(): void
    {
        $this->pupil('Іваненко Марія', 5, 'А', '2026-09-01', 230);

        Livewire::test(ListOrderLines::class)
            ->callAction('exportPupils', data: [
                'from' => '2026-09-01',
                'to' => '2026-09-04',
            ])
            ->assertFileDownloaded();
    }

    public function test_export_teachers_downloads_a_file(): void
    {
        $this->teacher('Коваленко Ольга', '2026-09-01', 230);

        Livewire::test(ListOrderLines::class)
            ->callAction('exportTeachers', data: [
                'from' => '2026-09-01',
                'to' => '2026-09-04',
            ])
            ->assertFileDownloaded();
    }

    private function pupil(string $name, int $grade, string $letter, string $date, float $price): void
    {
        $supplier = Supplier::firstOrCreate(['slug' => 'smachno'], ['name' => 'Смачно']);

        $user = User::create([
            'name' => $name,
            'login' => strtolower(str_replace(' ', '.', $name)),
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => SchoolClass::firstOrCreate(['grade' => $grade, 'letter' => $letter, 'academic_year' => 2026])->id,
            'consent_at' => now(),
        ]);

        $this->orderLine($student, $supplier, $date, $price);
    }

    private function teacher(string $name, string $date, float $price): void
    {
        $supplier = Supplier::firstOrCreate(['slug' => 'smachno'], ['name' => 'Смачно']);

        $user = User::create([
            'name' => $name,
            'login' => strtolower(str_replace(' ', '.', $name)),
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => null,
            'consent_at' => now(),
        ]);

        $this->orderLine($student, $supplier, $date, $price);
    }

    private function orderLine(Student $student, Supplier $supplier, string $date, float $price): void
    {
        $order = Order::create([
            'number' => 'ЗМ-TEST-'.uniqid(),
            'student_id' => $student->id,
            'school_class_id' => $student->school_class_id,
            'placed_at' => now(),
            'total_amount' => $price,
        ]);

        $order->lines()->create([
            'student_id' => $student->id,
            'supplier_id' => $supplier->id,
            'service_date' => $date,
            'dish_id' => null,
            'dish_name' => 'Тестова страва',
            'section_type' => MenuSectionType::Complex,
            'section_title' => 'Комплекс',
            'quantity' => 1,
            'unit_price' => $price,
        ]);
    }
}
