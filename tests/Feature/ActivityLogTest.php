<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Models\DeadlineRule;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\MenuSection;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Orders\CancellationService;
use App\Services\Orders\CartService;
use App\Services\Orders\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_DATE = '2026-08-17';

    private Supplier $supplier;

    private Student $student;

    private User $user;

    private Dish $cutlet;

    private MenuSection $complex;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-10 10:00:00');

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        DeadlineRule::create([
            'supplier_id' => $this->supplier->id,
            'weekday' => 1,
            'order_offset_days' => 1,
            'order_time' => '09:00:00',
            'cancel_offset_days' => 1,
            'cancel_time' => '09:00:00',
        ]);

        $this->user = User::create([
            'name' => 'Іваненко Марія',
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        $this->student = Student::create([
            'user_id' => $this->user->id,
            'full_name' => 'Іваненко Марія',
            'school_class_id' => SchoolClass::create([
                'grade' => 5, 'letter' => 'А', 'academic_year' => 2026,
            ])->id,
        ]);

        $this->cutlet = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Куряча котлета',
            'price' => 60,
        ]);

        $menuDay = MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => self::SERVICE_DATE,
            'is_working_day' => true,
            'published_at' => now(),
        ]);

        $this->complex = $menuDay->sections()->create([
            'type' => MenuSectionType::Complex,
            'title' => 'Комплекс №1',
            'sort' => 0,
        ]);
        $this->complex->sectionDishes()->create(['dish_id' => $this->cutlet->id, 'sort' => 0]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_order_composition_is_recorded_at_creation(): void
    {
        $this->actingAs($this->user);

        $order = $this->placeOrder();

        $activity = Activity::query()->where('event', 'order_placed')->firstOrFail();

        $this->assertSame($order->number, $activity->properties['number']);
        $this->assertSame('Куряча котлета', $activity->properties['lines'][0]['dish']);
        $this->assertSame('60.00', $activity->properties['lines'][0]['price']);
        $this->assertSame($this->user->id, $activity->causer_id);
    }

    public function test_price_change_is_recorded_with_old_and_new_value(): void
    {
        $this->cutlet->update(['price' => 75]);

        $activity = Activity::query()
            ->where('subject_type', Dish::class)
            ->where('description', 'like', '%змінено%')
            ->firstOrFail();

        $this->assertSame('60.00', (string) $activity->properties['old']['price']);
        $this->assertSame('75.00', (string) $activity->properties['attributes']['price']);
        $this->assertSame($this->supplier->id, $activity->properties['supplier_id']);
    }

    public function test_cancellation_records_actor_and_reason(): void
    {
        $order = $this->placeOrder();

        $admin = User::create([
            'name' => 'Адміністратор',
            'email' => 'admin@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]);

        app(CancellationService::class)->cancelLine(
            $order->lines()->firstOrFail(),
            $admin,
            reason: 'Карантин',
            bypassDeadline: true,
        );

        $activity = Activity::query()->where('event', 'line_cancelled')->firstOrFail();

        $this->assertSame($admin->id, $activity->causer_id);
        $this->assertSame('Карантин', $activity->properties['reason']);
        $this->assertTrue($activity->properties['past_deadline']);
        $this->assertStringContainsString('поза дедлайном', $activity->description);
    }

    public function test_deadline_change_is_recorded(): void
    {
        DeadlineRule::query()->first()->update(['order_time' => '11:00:00']);

        $activity = Activity::query()
            ->where('subject_type', DeadlineRule::class)
            ->where('description', 'like', '%змінено%')
            ->firstOrFail();

        $this->assertSame('11:00:00', $activity->properties['attributes']['order_time']);
    }

    public function test_supplier_activities_are_tagged_with_supplier_id(): void
    {
        $other = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);
        $foreignDish = Dish::create(['supplier_id' => $other->id, 'name' => 'Сирники', 'price' => 45]);

        $this->cutlet->update(['price' => 70]);
        $foreignDish->update(['price' => 50]);

        $mine = Activity::query()
            ->where('properties->supplier_id', $this->supplier->id)
            ->where('subject_type', Dish::class)
            ->get();

        $this->assertCount(2, $mine);   // створення страви + зміна ціни
        $this->assertTrue($mine->every(fn (Activity $a): bool => $a->properties['supplier_id'] === $this->supplier->id));
    }

    public function test_student_account_changes_are_recorded(): void
    {
        $this->student->update(['is_active' => false]);

        $activity = Activity::query()
            ->where('subject_type', Student::class)
            ->where('description', 'like', '%змінено%')
            ->firstOrFail();

        $this->assertFalse($activity->properties['attributes']['is_active']);
    }

    private function placeOrder(): \App\Models\Order
    {
        $cart = app(CartService::class);
        $cart->add($cart->for($this->student), $this->complex, $this->cutlet->id);

        return app(OrderService::class)->placeFromCart($this->student);
    }
}
