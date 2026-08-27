<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Supplier\Pages\KitchenReports;
use App\Models\Dish;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierReportsPageTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_DATE = '2026-08-17';

    private Supplier $supplier;

    private Supplier $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('supplier');

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $this->otherSupplier = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        $this->actingAs(User::create([
            'name' => 'Смачно',
            'email' => 'smachno@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $this->supplier->id,
        ]));
    }

    public function test_report_page_shows_summary_and_handout_list(): void
    {
        $this->line('Іваненко Марія', 5, 'А', 'Куряча котлета', 2);

        Livewire::test(KitchenReports::class)
            ->set('data.date', self::SERVICE_DATE)
            ->assertSee('Куряча котлета')
            ->assertSee('Іваненко Марія')
            ->assertSee('5-А');
    }

    public function test_report_page_ignores_other_suppliers(): void
    {
        $this->line('Петренко Іван', 5, 'А', 'Сирники', 1, $this->otherSupplier);

        Livewire::test(KitchenReports::class)
            ->set('data.date', self::SERVICE_DATE)
            ->assertDontSee('Сирники');
    }

    public function test_excel_export_returns_a_file(): void
    {
        $this->line('Іваненко Марія', 5, 'А', 'Куряча котлета', 1);

        Livewire::test(KitchenReports::class)
            ->set('data.date', self::SERVICE_DATE)
            ->callAction('excel')
            ->assertFileDownloaded('kuhnia-smachno-2026-08-17.xlsx');
    }

    public function test_pdf_export_returns_a_file(): void
    {
        $this->line('Іваненко Марія', 5, 'А', 'Куряча котлета', 1);

        Livewire::test(KitchenReports::class)
            ->set('data.date', self::SERVICE_DATE)
            ->callAction('pdf')
            ->assertFileDownloaded('kuhnia-smachno-2026-08-17.pdf');
    }

    private function line(
        string $name,
        int $grade,
        string $letter,
        string $dishName,
        int $quantity,
        ?Supplier $supplier = null,
    ): void {
        $supplier ??= $this->supplier;

        $user = User::create([
            'name' => $name,
            'login' => uniqid('student-'),
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        $student = Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => SchoolClass::query()->firstOrCreate([
                'academic_year' => 2026, 'grade' => $grade, 'letter' => $letter,
            ])->id,
        ]);

        $dish = Dish::create(['supplier_id' => $supplier->id, 'name' => $dishName, 'price' => 60]);

        $order = Order::create([
            'number' => uniqid('ЗМ-'),
            'student_id' => $student->id,
            'school_class_id' => $student->school_class_id,
            'placed_at' => now(),
        ]);

        $order->lines()->create([
            'student_id' => $student->id,
            'supplier_id' => $supplier->id,
            'service_date' => self::SERVICE_DATE,
            'dish_id' => $dish->id,
            'dish_name' => $dishName,
            'quantity' => $quantity,
            'unit_price' => 60,
        ]);
    }
}
