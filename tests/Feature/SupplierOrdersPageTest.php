<?php

namespace Tests\Feature;

use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Filament\Supplier\Resources\Orders\Pages\ListOrders;
use App\Filament\Supplier\Resources\Orders\Pages\ViewOrder;
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

    public function test_list_shows_number_student_and_own_total(): void
    {
        $order = $this->order();
        $this->line($order, $this->supplier, 'Куряча котлета', 2, 60);
        $this->line($order, $this->supplier, 'Борщ', 1, 40);
        // Страва іншого постачальника в тому самому чеку не має впливати на суму.
        $this->line($order, $this->otherSupplier, 'Сирники', 1, 45);

        Livewire::test(ListOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertSee($order->number)
            ->assertSee('Іваненко Марія')
            ->assertSee('160,00');
    }

    public function test_cancelled_lines_do_not_inflate_the_total(): void
    {
        $order = $this->order();
        $this->line($order, $this->supplier, 'Куряча котлета', 1, 60);
        $this->line($order, $this->supplier, 'Борщ', 1, 40, OrderLineStatus::Cancelled);

        Livewire::test(ListOrders::class)->assertSee('60,00');
    }

    public function test_orders_without_own_lines_are_hidden(): void
    {
        $foreign = $this->order();
        $this->line($foreign, $this->otherSupplier, 'Сирники', 1, 45);

        Livewire::test(ListOrders::class)->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_detail_page_shows_composition_and_who_ordered(): void
    {
        $order = $this->order();
        $this->line($order, $this->supplier, 'Куряча котлета', 2, 60);
        $this->line($order, $this->otherSupplier, 'Сирники', 1, 45);

        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->assertSee('Куряча котлета')
            ->assertSee('Іваненко Марія')
            ->assertSee('5-А')
            ->assertSee($order->number)
            ->assertSee('120,00')
            // Чужа страва в картку не потрапляє.
            ->assertDontSee('Сирники');
    }

    public function test_supplier_cannot_open_a_foreign_order(): void
    {
        $foreign = $this->order();
        $this->line($foreign, $this->otherSupplier, 'Сирники', 1, 45);

        $this->get(route('filament.supplier.resources.orders.view', ['record' => $foreign->getKey()]))
            ->assertNotFound();
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
