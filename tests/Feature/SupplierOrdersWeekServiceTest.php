<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Models\Dish;
use App\Models\MenuDay;
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

    public function test_columns_go_first_course_then_complex_then_extras(): void
    {
        $student = $this->pupil('Іваненко Марія', 5, 'А');

        $this->line($student, self::FROM, MenuSectionType::Extra, 'Напій', 'Мохіто', 35);
        $this->line($student, self::FROM, MenuSectionType::Complex, 'Комплекс №1', 'Комплекс №1: Салат, Котлета', 230);
        $this->line($student, self::FROM, MenuSectionType::Choice, 'Перше', 'Борщ', 45);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $this->assertSame(['Перше', 'Комплекс №1', 'Напій'], $report['columns']->pluck('section_title')->all());
    }

    public function test_menu_sections_get_columns_even_without_orders(): void
    {
        $student = $this->pupil('Іваненко Марія', 5, 'А');

        // Меню на понеділок і вівторок; замовлено лише комплекс у понеділок.
        $this->menu(self::FROM);
        $this->menu('2026-09-08');
        $this->line($student, self::FROM, MenuSectionType::Complex, 'Комплекс', 'Комплекс: Салат, Котлета', 230);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $this->assertSame(
            ['Перше', 'Комплекс', 'Напій', 'Перше', 'Комплекс', 'Напій'],
            $report['columns']->pluck('section_title')->all(),
        );
        $this->assertSame([true, false, false, true, false, false], $report['columns']->pluck('first_of_day')->all());

        $soup = $report['columns']->first();
        $this->assertSame(45.0, $soup['price']);

        $complex = $report['columns'][1];
        $this->assertSame(230.0, $complex['price']);
        $this->assertSame('Салат, Котлета', $complex['complex_dishes']);

        $this->assertSame('Комплекс', $report['rows']->sole()['cells'][self::FROM.'|Комплекс']);
    }

    public function test_cancelled_lines_are_marked_only_on_request(): void
    {
        $student = $this->pupil('Іваненко Марія', 5, 'А');
        $other = $this->pupil('Петренко Іван', 5, 'А');

        // Марія скасувала борщ і замовила рибну юшку; Іван лише скасував.
        $this->line($student, self::FROM, MenuSectionType::Choice, 'Перше', 'Борщ', 45, status: OrderLineStatus::Cancelled);
        $this->line($student, self::FROM, MenuSectionType::Choice, 'Перше', 'Рибна юшка', 45);
        $this->line($other, self::FROM, MenuSectionType::Choice, 'Перше', 'Борщ', 45, status: OrderLineStatus::Cancelled);

        $this->assertCount(1, $this->service->build($this->supplier, self::FROM, self::TO)['rows']);

        $rows = $this->service->build($this->supplier, self::FROM, self::TO, withCancelled: true)['rows']->keyBy('full_name');
        $key = self::FROM.'|Перше';

        $this->assertSame('Рибна юшка', $rows['Іваненко Марія']['cells'][$key]);
        $this->assertArrayNotHasKey($key, $rows['Іваненко Марія']['cancelled']);

        $this->assertSame('Борщ', $rows['Петренко Іван']['cells'][$key]);
        $this->assertTrue($rows['Петренко Іван']['cancelled'][$key]);
    }

    public function test_menu_columns_are_returned_when_nobody_ordered(): void
    {
        $this->menu(self::FROM);

        $report = $this->service->build($this->supplier, self::FROM, self::TO);

        $this->assertCount(3, $report['columns']);
        $this->assertTrue($report['rows']->isEmpty());
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

    /** Меню дня в порядку, як його склав постачальник: комплекс, напій, перше. */
    private function menu(string $date): MenuDay
    {
        $menuDay = MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => $date,
            'is_working_day' => true,
            'published_at' => now(),
        ]);

        $dish = fn (string $name, float $price): Dish => Dish::query()->firstOrCreate(
            ['supplier_id' => $this->supplier->id, 'name' => $name],
            ['price' => $price],
        );

        $complex = $menuDay->sections()->create(['type' => MenuSectionType::Complex, 'title' => 'Комплекс', 'price' => 230, 'sort' => 0]);
        $complex->sectionDishes()->create(['dish_id' => $dish('Салат', 0)->id, 'sort' => 0]);
        $complex->sectionDishes()->create(['dish_id' => $dish('Котлета', 0)->id, 'sort' => 1]);

        $drink = $menuDay->sections()->create(['type' => MenuSectionType::Extra, 'title' => 'Напій', 'sort' => 1]);
        $drink->sectionDishes()->create(['dish_id' => $dish('Компот', 15)->id, 'sort' => 0]);

        $soup = $menuDay->sections()->create(['type' => MenuSectionType::Choice, 'title' => 'Перше', 'sort' => 2]);
        $soup->sectionDishes()->create(['dish_id' => $dish('Борщ', 45)->id, 'sort' => 0]);
        $soup->sectionDishes()->create(['dish_id' => $dish('Суп з локшиною', 45)->id, 'sort' => 1]);

        return $menuDay;
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
