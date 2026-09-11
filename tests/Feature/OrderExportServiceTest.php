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
use App\Services\Reports\OrderExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-09-01';   // вівторок

    private const TO = '2026-09-04';   // п'ятниця

    private OrderExportService $service;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OrderExportService::class);
        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
    }

    public function test_pupil_row_sums_several_lines_on_the_same_day(): void
    {
        $student = $this->pupil('Іваненко Марія', grade: 5, letter: 'А');

        // Комплекс + перша страва окремо того самого дня — сума має скластися.
        $this->orderLine($student, self::FROM, 230);
        $this->orderLine($student, self::FROM, 45);

        $reports = $this->service->pupilReportsBySupplier(self::FROM, self::TO);

        $this->assertCount(1, $reports);
        $row = $reports->first()['rows']->first();

        $this->assertSame('Іваненко Марія', $row['full_name']);
        $this->assertSame('5-А', $row['class']);
        $this->assertSame(275.0, $row['by_date'][self::FROM]);
        $this->assertSame(275.0, $row['total']);
    }

    public function test_days_without_orders_are_zero(): void
    {
        $student = $this->pupil('Петренко Іван', grade: 7, letter: 'Б');

        $this->orderLine($student, self::FROM, 100);

        $row = $this->service->pupilReportsBySupplier(self::FROM, self::TO)->first()['rows']->first();

        $this->assertSame(100.0, $row['by_date'][self::FROM]);
        $this->assertSame(0.0, $row['by_date']['2026-09-02']);
        $this->assertSame(0.0, $row['by_date']['2026-09-03']);
        $this->assertSame(0.0, $row['by_date']['2026-09-04']);
        $this->assertSame(100.0, $row['total']);
    }

    public function test_cancelled_lines_are_not_counted(): void
    {
        $student = $this->pupil('Скасований Учень', grade: 5, letter: 'А');

        $this->orderLine($student, self::FROM, 230);
        $this->orderLine($student, self::FROM, 45, status: OrderLineStatus::Cancelled);

        $row = $this->service->pupilReportsBySupplier(self::FROM, self::TO)->first()['rows']->first();

        $this->assertSame(230.0, $row['by_date'][self::FROM]);
        $this->assertSame(230.0, $row['total']);
    }

    public function test_teacher_row_has_no_class(): void
    {
        $teacher = $this->teacher('Коваленко Ольга');

        $this->orderLine($teacher, self::FROM, 230);

        $reports = $this->service->teacherReportsBySupplier(self::FROM, self::TO);

        $this->assertCount(1, $reports);
        $row = $reports->first()['rows']->first();
        $this->assertSame('', $row['class']);
        $this->assertSame(230.0, $row['total']);
    }

    public function test_teachers_do_not_leak_into_pupil_reports(): void
    {
        $teacher = $this->teacher('Коваленко Ольга');
        $this->orderLine($teacher, self::FROM, 230);

        $pupil = $this->pupil('Іваненко Марія', grade: 5, letter: 'А');
        $this->orderLine($pupil, self::FROM, 230);

        $this->assertCount(1, $this->service->pupilReportsBySupplier(self::FROM, self::TO)->first()['rows']);
        $this->assertCount(1, $this->service->teacherReportsBySupplier(self::FROM, self::TO)->first()['rows']);
    }

    public function test_a_person_without_orders_in_the_period_is_not_listed(): void
    {
        $this->pupil('Не замовляв', grade: 5, letter: 'А');

        $this->assertCount(0, $this->service->pupilReportsBySupplier(self::FROM, self::TO));
    }

    public function test_orders_outside_the_period_do_not_include_a_person(): void
    {
        $student = $this->pupil('Замовляв іншого тижня', grade: 5, letter: 'А');
        $this->orderLine($student, '2026-08-25', 230);

        $this->assertCount(0, $this->service->pupilReportsBySupplier(self::FROM, self::TO));
    }

    public function test_inactive_student_is_excluded(): void
    {
        $student = $this->pupil('Неактивний Учень', grade: 5, letter: 'А');
        $student->update(['is_active' => false]);

        $this->orderLine($student, self::FROM, 230);

        $this->assertCount(0, $this->service->pupilReportsBySupplier(self::FROM, self::TO));
    }

    public function test_each_supplier_gets_its_own_report_with_only_its_own_sums(): void
    {
        $otherSupplier = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        $student = $this->pupil('Іваненко Марія', grade: 5, letter: 'А');

        $this->orderLine($student, self::FROM, 230);
        $this->orderLine($student, '2026-09-03', 230);
        $this->orderLine($student, self::FROM, 100, supplier: $otherSupplier);

        $reports = $this->service->pupilReportsBySupplier(self::FROM, self::TO);

        $this->assertCount(2, $reports);

        $smachno = $reports->firstWhere(fn (array $r): bool => $r['supplier']->id === $this->supplier->id);
        $domashnya = $reports->firstWhere(fn (array $r): bool => $r['supplier']->id === $otherSupplier->id);

        $this->assertSame(230.0, $smachno['rows']->first()['by_date'][self::FROM]);
        $this->assertSame(230.0, $smachno['rows']->first()['by_date']['2026-09-03']);
        $this->assertSame(460.0, $smachno['rows']->first()['total']);

        $this->assertSame(100.0, $domashnya['rows']->first()['by_date'][self::FROM]);
        $this->assertSame(100.0, $domashnya['rows']->first()['total']);
    }

    public function test_a_person_who_did_not_order_from_this_supplier_is_absent_from_its_sheet(): void
    {
        $otherSupplier = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        $student = $this->pupil('Іваненко Марія', grade: 5, letter: 'А');
        $this->orderLine($student, self::FROM, 230, supplier: $otherSupplier);

        $reports = $this->service->pupilReportsBySupplier(self::FROM, self::TO);

        $this->assertCount(1, $reports);
        $this->assertSame('Домашня кухня', $reports->first()['supplier']->name);
    }

    private function pupil(string $name, int $grade, string $letter): Student
    {
        $user = User::create([
            'name' => $name,
            'login' => strtolower(str_replace(' ', '.', $name)),
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => SchoolClass::firstOrCreate(['grade' => $grade, 'letter' => $letter, 'academic_year' => 2026])->id,
            'consent_at' => now(),
        ]);
    }

    private function teacher(string $name): Student
    {
        $user = User::create([
            'name' => $name,
            'login' => strtolower(str_replace(' ', '.', $name)),
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => null,
            'consent_at' => now(),
        ]);
    }

    private function orderLine(Student $student, string $date, float $price, OrderLineStatus $status = OrderLineStatus::Active, ?Supplier $supplier = null): void
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
            'supplier_id' => ($supplier ?? $this->supplier)->id,
            'service_date' => $date,
            'dish_id' => null,
            'dish_name' => 'Тестова страва',
            'section_type' => MenuSectionType::Complex,
            'section_title' => 'Комплекс',
            'quantity' => 1,
            'unit_price' => $price,
            'status' => $status,
        ]);
    }
}
