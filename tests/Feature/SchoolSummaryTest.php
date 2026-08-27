<?php

namespace Tests\Feature;

use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Filament\Pages\SchoolSettings;
use App\Filament\Pages\SchoolSummary;
use App\Models\Dish;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\SchoolSummaryService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SchoolSummaryTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_DATE = '2026-08-17';

    private Supplier $smachno;

    private Supplier $domashnya;

    private SchoolSummaryService $summary;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->actingAs(User::create([
            'name' => 'Адміністратор',
            'email' => 'admin@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]));

        $this->smachno = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $this->domashnya = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);
        $this->summary = app(SchoolSummaryService::class);
    }

    public function test_day_summary_counts_by_supplier(): void
    {
        $maria = $this->student('Іваненко Марія');
        $ivan = $this->student('Петренко Іван');

        $this->line($maria, $this->smachno, 'Котлета', 2);
        $this->line($ivan, $this->smachno, 'Котлета', 1);
        $this->line($ivan, $this->domashnya, 'Сирники', 1);

        $days = $this->summary->byDay(self::SERVICE_DATE, self::SERVICE_DATE);

        $this->assertCount(1, $days);
        $this->assertSame(3, $days[0]['suppliers']['Смачно']);
        $this->assertSame(1, $days[0]['suppliers']['Домашня кухня']);
        $this->assertSame(4, $days[0]['positions']);
        $this->assertSame(2, $days[0]['students']);
    }

    public function test_summary_covers_every_day_of_the_range(): void
    {
        $days = $this->summary->byDay('2026-08-17', '2026-08-21');

        $this->assertCount(5, $days);
        $this->assertSame(0, $days[4]['positions']);
    }

    public function test_last_day_of_the_range_is_included(): void
    {
        $maria = $this->student('Іваненко Марія');

        $this->line($maria, $this->smachno, 'Котлета', 1);

        // Дата збігається з верхньою межею діапазону — саме тут ховалася помилка.
        $days = $this->summary->byDay('2026-08-13', self::SERVICE_DATE);

        $this->assertSame(1, $days->last()['positions']);
    }

    public function test_cancelled_lines_are_not_counted(): void
    {
        $maria = $this->student('Іваненко Марія');

        $this->line($maria, $this->smachno, 'Котлета', 1, OrderLineStatus::Cancelled);

        $days = $this->summary->byDay(self::SERVICE_DATE, self::SERVICE_DATE);

        $this->assertSame(0, $days[0]['positions']);
    }

    public function test_students_without_orders_are_listed(): void
    {
        $ordering = $this->student('Іваненко Марія');
        $this->student('Петренко Іван');
        $inactive = $this->student('Випускник Старий');
        $inactive->update(['is_active' => false]);

        $this->line($ordering, $this->smachno, 'Котлета', 1);

        $missing = $this->summary->studentsWithoutOrders(self::SERVICE_DATE);

        $this->assertCount(1, $missing);
        $this->assertSame('Петренко Іван', $missing->first()->full_name);
    }

    public function test_summary_page_renders(): void
    {
        $maria = $this->student('Іваненко Марія');
        $this->line($maria, $this->smachno, 'Котлета', 1);
        $this->student('Петренко Іван');

        Livewire::test(SchoolSummary::class)
            ->set('data.from', self::SERVICE_DATE)
            ->set('data.to', self::SERVICE_DATE)
            ->set('data.missing_date', self::SERVICE_DATE)
            ->assertSee('Смачно')
            ->assertSee('Петренко Іван');
    }

    public function test_summary_is_exported_to_pdf(): void
    {
        $maria = $this->student('Іваненко Марія');
        $this->line($maria, $this->smachno, 'Котлета', 1);

        Livewire::test(SchoolSummary::class)
            ->set('data.from', self::SERVICE_DATE)
            ->set('data.to', self::SERVICE_DATE)
            ->set('data.missing_date', self::SERVICE_DATE)
            ->callAction('pdf')
            ->assertFileDownloaded('zvedennia-2026-08-17-2026-08-17.pdf');
    }

    public function test_settings_are_saved(): void
    {
        Livewire::test(SchoolSettings::class)
            ->set('data.school_name', 'Ліцей №7')
            ->set('data.deadline_reminder_hours', '5')
            ->call('save');

        $this->assertSame('Ліцей №7', Setting::get('school_name'));
        $this->assertSame('5', Setting::get('deadline_reminder_hours'));
    }

    private function student(string $name): Student
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
            'school_class_id' => SchoolClass::query()->firstOrCreate([
                'academic_year' => 2026, 'grade' => 5, 'letter' => 'А',
            ])->id,
        ]);
    }

    private function line(
        Student $student,
        Supplier $supplier,
        string $dishName,
        int $quantity,
        OrderLineStatus $status = OrderLineStatus::Active,
    ): void {
        $dish = Dish::query()->firstOrCreate(
            ['supplier_id' => $supplier->id, 'name' => $dishName],
            ['price' => 50],
        );

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
            'unit_price' => 50,
            'status' => $status,
        ]);
    }
}
