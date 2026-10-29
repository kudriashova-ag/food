<?php

namespace Tests\Feature;

use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Filament\Supplier\Resources\Orders\Pages\ListOrders;
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

class SupplierOrdersPageTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_DATE = '2026-08-17';

    private Supplier $supplier;

    private Supplier $otherSupplier;

    private Student $student;

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

        $studentUser = User::create([
            'name' => 'Іваненко Марія',
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        $this->student = Student::create([
            'user_id' => $studentUser->id,
            'full_name' => 'Іваненко Марія',
            'school_class_id' => SchoolClass::create([
                'grade' => 5, 'letter' => 'А', 'academic_year' => 2026,
            ])->id,
        ]);
    }

    public function test_current_week_is_shown_by_default(): void
    {
        $this->travelTo(self::SERVICE_DATE.' 10:00');

        $order = $this->order();
        $this->line($order, $this->supplier, 'Куряча котлета', 1, 60);

        Livewire::test(ListOrders::class)
            ->assertSee('Замовлення з 17.08 по 21.08.2026')
            ->assertSee('Іваненко Марія')
            ->assertSee('5-А')
            ->assertSee('Куряча котлета');
    }

    public function test_period_can_be_changed(): void
    {
        $this->travelTo(self::SERVICE_DATE.' 10:00');

        $order = $this->order();
        $this->line($order, $this->supplier, 'Куряча котлета', 1, 60);

        Livewire::test(ListOrders::class)
            ->set('data.from', '2026-08-24')
            ->set('data.to', '2026-08-28')
            ->assertDontSee('Іваненко Марія')
            ->assertSee('За цей період немає ні меню, ні замовлень.');
    }

    public function test_cancelled_lines_and_other_suppliers_are_not_shown(): void
    {
        $this->travelTo(self::SERVICE_DATE.' 10:00');

        $order = $this->order();
        $this->line($order, $this->supplier, 'Куряча котлета', 1, 60);
        $this->line($order, $this->supplier, 'Борщ', 1, 40, OrderLineStatus::Cancelled);
        // Страва іншого постачальника в тому самому чеку сюди не потрапляє.
        $this->line($order, $this->otherSupplier, 'Сирники', 1, 45);

        Livewire::test(ListOrders::class)
            ->assertSee('Куряча котлета')
            ->assertDontSee('Борщ')
            ->assertDontSee('Сирники');
    }

    public function test_google_drive_folder_button_appears_once_the_folder_exists(): void
    {
        Livewire::test(ListOrders::class)->assertActionHidden('driveFolder');

        \App\Models\GoogleDriveItem::create(['key' => "supplier:{$this->supplier->id}", 'drive_id' => 'folder-123']);

        Livewire::test(ListOrders::class)
            ->assertActionVisible('driveFolder')
            ->assertSee('https://drive.google.com/drive/folders/folder-123');
    }

    public function test_day_export_returns_a_file(): void
    {
        $order = $this->order();
        $this->line($order, $this->supplier, 'Куряча котлета', 1, 60);

        Livewire::test(ListOrders::class)
            ->callAction('excel', data: ['date' => self::SERVICE_DATE])
            ->assertFileDownloaded('zamovlennia-smachno-2026-08-17.xlsx');
    }

    public function test_week_orders_export_returns_a_file(): void
    {
        $order = $this->order();
        $this->line($order, $this->supplier, 'Куряча котлета', 1, 60);

        Livewire::test(ListOrders::class)
            ->callAction('weekOrders', data: [
                'from' => self::SERVICE_DATE,
                'to' => '2026-08-21',
            ])
            ->assertFileDownloaded('zamovlennia-smachno-2026-08-17-2026-08-21.xlsx');
    }

    private function order(): Order
    {
        return Order::create([
            'number' => uniqid('ЗМ-'),
            'student_id' => $this->student->id,
            'school_class_id' => $this->student->school_class_id,
            'placed_at' => now(),
        ]);
    }

    private function line(
        Order $order,
        Supplier $supplier,
        string $dishName,
        int $quantity,
        float $price,
        OrderLineStatus $status = OrderLineStatus::Active,
    ): void {
        $dish = Dish::query()->firstOrCreate(
            ['supplier_id' => $supplier->id, 'name' => $dishName],
            ['price' => $price],
        );

        $order->lines()->create([
            'student_id' => $this->student->id,
            'supplier_id' => $supplier->id,
            'service_date' => self::SERVICE_DATE,
            'dish_id' => $dish->id,
            'dish_name' => $dishName,
            'quantity' => $quantity,
            'unit_price' => $price,
            'status' => $status,
        ]);

        $order->recalculateTotal();
    }
}
