<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\SupplierOrdersWeekService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierOrdersWeekServiceTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-09-07';   // понеділок

    private const TO = '2026-09-11';   // п'ятниця

    private SupplierOrdersWeekService $service;

    private Supplier $supplier;

    private Supplier $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SupplierOrdersWeekService::class);
        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $this->otherSupplier = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);
    }

    public function test_columns_are_one_per_section_per_day(): void
    {
        $student = $this->pupil('Іваненко Марія', 5, 'А');

        $this->line($student, self::FROM, MenuSectionType::Choice, 'Перше', 'Борщ', 45);
        $this->line($student, self::FROM, MenuSectionType::Extra, 'Напій', 'Мохіто', 35);
        $this->line($student, '2026-09-08', MenuSectionType::Choice, 'Перше', 'Рибна юшка', 45);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        // 3 колонки: Перше (Пн), Напій (Пн), Перше (Вт)
        $this->assertCount(3, $report['columns']);

        $titles = $report['columns']->pluck('section_title')->all();
        $this->assertSame(['Перше', 'Напій', 'Перше'], $titles);
    }

    public function test_complex_cell_says_just_complex_and_subtitle_holds_the_composition(): void
    {
        $student = $this->pupil('Іваненко Марія', 5, 'А');

        $this->line(
            $student,
            self::FROM,
            MenuSectionType::Complex,
            'Комплекс №1',
            'Комплекс №1: Салат, Котлета куряча, Каша гречана',
            230,
        );

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $column = $report['columns']->first();
        $this->assertTrue($column['is_complex']);
        $this->assertSame('Салат, Котлета куряча, Каша гречана', $column['complex_dishes']);
        $this->assertSame(230.0, $column['price']);

        $row = $report['rows']->first();
        $this->assertSame('Комплекс', $row['cells'][$column['key']]);
    }

    public function test_choice_and_extra_cells_hold_the_dish_name(): void
    {
        $student = $this->pupil('Іваненко Марія', 5, 'А');

        $this->line($student, self::FROM, MenuSectionType::Choice, 'Перше', 'Рибна юшка', 45);
        $this->line($student, self::FROM, MenuSectionType::Extra, 'Напій', 'Мохіто', 35);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $cells = $report['rows']->first()['cells'];
        $this->assertContains('Рибна юшка', $cells);
        $this->assertContains('Мохіто', $cells);
    }

    public function test_teacher_row_class_says_vchytel(): void
    {
        $teacher = $this->teacher('Коваленко Ольга');

        $this->line($teacher, self::FROM, MenuSectionType::Choice, 'Перше', 'Борщ', 45);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $this->assertSame('Вчитель', $report['rows']->first()['class']);
    }

    public function test_only_this_suppliers_lines_are_included(): void
    {
        $student = $this->pupil('Іваненко Марія', 5, 'А');

        $this->line($student, self::FROM, MenuSectionType::Choice, 'Перше', 'Борщ', 45);
        $this->line($student, self::FROM, MenuSectionType::Choice, 'Перше', 'Сирники', 40, $this->otherSupplier);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $this->assertCount(1, $report['columns']);
        $this->assertContains('Борщ', $report['rows']->first()['cells']);
        $this->assertNotContains('Сирники', $report['rows']->first()['cells']);
    }

    public function test_cancelled_lines_are_ignored(): void
    {
        $student = $this->pupil('Іваненко Марія', 5, 'А');

        $this->line($student, self::FROM, MenuSectionType::Choice, 'Перше', 'Борщ', 45, status: OrderLineStatus::Cancelled);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $this->assertCount(0, $report['columns']);
        $this->assertCount(0, $report['rows']);
    }

    public function test_only_students_who_ordered_in_the_period_appear(): void
    {
        $ordered = $this->pupil('Замовляв', 5, 'А');
        $this->pupil('Не замовляв', 5, 'А');

        $this->line($ordered, self::FROM, MenuSectionType::Choice, 'Перше', 'Борщ', 45);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $this->assertCount(1, $report['rows']);
        $this->assertSame('Замовляв', $report['rows']->first()['full_name']);
    }

    public function test_lines_outside_the_period_are_excluded(): void
    {
        $student = $this->pupil('Іваненко Марія', 5, 'А');

        $this->line($student, '2026-09-01', MenuSectionType::Choice, 'Перше', 'Борщ', 45);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $this->assertCount(0, $report['rows']);
    }

    private function pupil(string $name, int $grade, string $letter): Student
    {
        $user = User::create([
            'name' => $name,
            'login' => uniqid('student-'),
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => SchoolClass::query()->firstOrCreate(['academic_year' => 2026, 'grade' => $grade, 'letter' => $letter])->id,
        ]);
    }

    private function teacher(string $name): Student
    {
        $user = User::create([
            'name' => $name,
            'login' => uniqid('teacher-'),
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => null,
        ]);
    }

    private function line(
        Student $student,
        string $date,
        MenuSectionType $type,
        string $sectionTitle,
        string $dishName,
        float $price,
        ?Supplier $supplier = null,
        OrderLineStatus $status = OrderLineStatus::Active,
    ): void {
        $supplier ??= $this->supplier;

        $order = Order::create([
            'number' => uniqid('ЗМ-'),
            'student_id' => $student->id,
            'school_class_id' => $student->school_class_id,
            'placed_at' => now(),
        ]);

        $order->lines()->create([
            'student_id' => $student->id,
            'supplier_id' => $supplier->id,
            'service_date' => $date,
            'dish_id' => null,
            'dish_name' => $dishName,
            'section_type' => $type,
            'section_title' => $sectionTitle,
            'quantity' => 1,
            'unit_price' => $price,
            'status' => $status,
        ]);
    }
}
