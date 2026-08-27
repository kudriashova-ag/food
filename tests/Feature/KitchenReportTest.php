<?php

namespace Tests\Feature;

use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\KitchenReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KitchenReportTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_DATE = '2026-08-17';

    private Supplier $supplier;

    private KitchenReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $this->reports = new KitchenReportService();
    }

    public function test_daily_summary_counts_dishes_and_students(): void
    {
        $maria = $this->student('Іваненко Марія', 5, 'А');
        $ivan = $this->student('Петренко Іван', 5, 'А');

        $this->line($maria, 'Куряча котлета', 1);
        $this->line($maria, 'Вода 0,5 л', 2);
        $this->line($ivan, 'Куряча котлета', 1);

        $summary = $this->reports->dailySummary($this->supplier, self::SERVICE_DATE);

        $this->assertSame(4, $summary['positions']);
        $this->assertSame(2, $summary['students']);
        $this->assertSame(
            [['name' => 'Куряча котлета', 'quantity' => 2], ['name' => 'Вода 0,5 л', 'quantity' => 2]],
            $summary['dishes']->all(),
        );
    }

    public function test_cancelled_lines_disappear_from_the_report(): void
    {
        $maria = $this->student('Іваненко Марія', 5, 'А');

        $this->line($maria, 'Куряча котлета', 1);
        $this->line($maria, 'Борщ', 1, OrderLineStatus::Cancelled);

        $summary = $this->reports->dailySummary($this->supplier, self::SERVICE_DATE);

        $this->assertSame(1, $summary['positions']);
        $this->assertCount(1, $summary['dishes']);
    }

    public function test_another_suppliers_lines_are_not_counted(): void
    {
        $other = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);
        $maria = $this->student('Іваненко Марія', 5, 'А');

        $this->line($maria, 'Сирники', 1, supplier: $other);

        $summary = $this->reports->dailySummary($this->supplier, self::SERVICE_DATE);

        $this->assertSame(0, $summary['positions']);
    }

    public function test_handout_list_is_grouped_by_class_and_student(): void
    {
        $maria = $this->student('Іваненко Марія', 5, 'А');
        $olha = $this->student('Коваленко Ольга', 7, 'Б');

        $this->line($maria, 'Куряча котлета', 1);
        $this->line($maria, 'Вода 0,5 л', 2);
        $this->line($olha, 'Сирники', 1);

        $list = $this->reports->handoutList($this->supplier, self::SERVICE_DATE);

        $this->assertCount(2, $list);
        $this->assertSame('5-А', $list[0]['class']);
        $this->assertSame('Іваненко Марія', $list[0]['students'][0]['name']);
        $this->assertSame('Куряча котлета, Вода 0,5 л ×2', $list[0]['students'][0]['dishes']);
        $this->assertSame('7-Б', $list[1]['class']);
    }

    private function student(string $name, int $grade, string $letter): Student
    {
        $user = User::create([
            'name' => $name,
            'login' => str()->slug($name).'-'.uniqid(),
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => SchoolClass::query()->firstOrCreate([
                'academic_year' => 2026,
                'grade' => $grade,
                'letter' => $letter,
            ])->id,
        ]);
    }

    private function line(
        Student $student,
        string $dishName,
        int $quantity,
        OrderLineStatus $status = OrderLineStatus::Active,
        ?Supplier $supplier = null,
    ): void {
        $supplier ??= $this->supplier;

        $dish = \App\Models\Dish::query()->firstOrCreate(
            ['supplier_id' => $supplier->id, 'name' => $dishName],
            ['price' => 50],
        );

        $order = Order::query()->firstOrCreate(
            ['number' => 'ЗМ-TEST-'.$student->id],
            [
                'student_id' => $student->id,
                'school_class_id' => $student->school_class_id,
                'placed_at' => now(),
            ],
        );

        $order->lines()->create([
            'student_id' => $student->id,
            'supplier_id' => $supplier->id,
            'service_date' => self::SERVICE_DATE,
            'dish_id' => $dish->id,
            'dish_name' => $dishName,
            'quantity' => $quantity,
            'unit_price' => 50,
            'status' => $status,
        ]);
    }
}
