<?php

namespace Tests\Feature;

use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Filament\Resources\OrderLines\Pages\ListOrderLines;
use App\Filament\Resources\SchoolClasses\Pages\ListSchoolClasses;
use App\Filament\Resources\Students\Pages\CreateStudent;
use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->admin = User::create([
            'name' => 'Адміністратор',
            'email' => 'admin@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($this->admin);
    }

    public function test_supplier_is_created_together_with_its_login(): void
    {
        Livewire::test(CreateSupplier::class)
            ->fillForm([
                'name' => 'Смачно',
                'slug' => 'smachno',
                'is_visible' => true,
                'account_email' => 'smachno@school.local',
                'account_password' => 'parol12345',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $supplier = Supplier::query()->firstOrFail();
        $account = $supplier->users()->firstOrFail();

        $this->assertSame(UserRole::Supplier, $account->role);
        $this->assertTrue(Hash::check('parol12345', $account->password));
    }

    public function test_student_is_created_with_a_user_account(): void
    {
        $class = SchoolClass::create(['grade' => 5, 'letter' => 'А', 'academic_year' => 2026]);

        Livewire::test(CreateStudent::class)
            ->fillForm([
                'full_name' => 'Іваненко Марія',
                'school_class_id' => $class->id,
                'is_active' => true,
                'login' => 'ivanenko.mariia',
                'password' => 'parol123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $student = Student::query()->firstOrFail();

        $this->assertSame('ivanenko.mariia', $student->user->login);
        $this->assertSame(UserRole::Student, $student->user->role);
    }

    public function test_promotion_moves_classes_and_graduates_the_oldest(): void
    {
        $fifth = SchoolClass::create(['grade' => 5, 'letter' => 'А', 'academic_year' => 2026]);
        $eleventh = SchoolClass::create(['grade' => 11, 'letter' => 'А', 'academic_year' => 2026]);

        $fifthGrader = $this->student('Іваненко Марія', $fifth);
        $graduate = $this->student('Петренко Іван', $eleventh);

        Livewire::test(ListSchoolClasses::class)
            ->callAction('promote', data: ['from_year' => 2026]);

        $fifthGrader->refresh();
        $graduate->refresh();

        $this->assertSame(6, $fifthGrader->schoolClass->grade);
        $this->assertSame(2027, $fifthGrader->schoolClass->academic_year);

        $this->assertFalse($graduate->is_active);
        $this->assertFalse($graduate->user->fresh()->is_active);
        // Клас випускника не змінився — історія лишилась як була.
        $this->assertSame(11, $graduate->schoolClass->grade);
    }

    public function test_admin_cancels_a_line_with_a_reason(): void
    {
        $line = $this->orderLine();

        Livewire::test(ListOrderLines::class)
            ->callTableAction('cancel', $line, ['reason' => 'Скасовано школою: карантин'])
            ->assertHasNoTableActionErrors();

        $line->refresh();

        $this->assertSame(OrderLineStatus::Cancelled, $line->status);
        $this->assertSame('Скасовано школою: карантин', $line->cancel_reason);
        $this->assertSame($this->admin->id, $line->cancelled_by);
    }

    public function test_cancellation_requires_a_reason(): void
    {
        $line = $this->orderLine();

        Livewire::test(ListOrderLines::class)
            ->callTableAction('cancel', $line, [])
            ->assertHasTableActionErrors(['reason']);

        $this->assertSame(OrderLineStatus::Active, $line->fresh()->status);
    }

    public function test_admin_sees_lines_of_every_supplier(): void
    {
        $first = $this->orderLine('Смачно', 'smachno');
        $second = $this->orderLine('Домашня кухня', 'domashnya');

        Livewire::test(ListOrderLines::class)
            ->assertCanSeeTableRecords([$first, $second]);
    }

    private function student(string $name, SchoolClass $class): Student
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
            'school_class_id' => $class->id,
        ]);
    }

    private function orderLine(string $supplierName = 'Смачно', string $slug = 'smachno'): OrderLine
    {
        $supplier = Supplier::query()->firstOrCreate(['slug' => $slug], ['name' => $supplierName]);

        $class = SchoolClass::query()->firstOrCreate([
            'grade' => 5, 'letter' => 'А', 'academic_year' => 2026,
        ]);

        $student = $this->student('Учень '.uniqid(), $class);

        $dish = Dish::create(['supplier_id' => $supplier->id, 'name' => 'Котлета', 'price' => 60]);

        $order = Order::create([
            'number' => uniqid('ЗМ-'),
            'student_id' => $student->id,
            'school_class_id' => $class->id,
            'placed_at' => now(),
        ]);

        return $order->lines()->create([
            'student_id' => $student->id,
            'supplier_id' => $supplier->id,
            'service_date' => today()->addDays(3),
            'dish_id' => $dish->id,
            'dish_name' => 'Котлета',
            'quantity' => 1,
            'unit_price' => 60,
        ]);
    }
}
