<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Models\DeadlineRule;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\MenuSection;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\DeadlineReminder;
use App\Notifications\OrderLinesCancelled;
use App\Notifications\OrderPlaced;
use App\Services\Orders\CancellationService;
use App\Services\Orders\CartService;
use App\Services\Orders\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_DATE = '2026-08-17';   // понеділок

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
            'email' => 'mama@example.com',
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

    public function test_student_is_notified_about_a_placed_order(): void
    {
        Notification::fake();

        $this->placeOrder();

        Notification::assertSentTo($this->user, OrderPlaced::class);
    }

    public function test_student_without_email_is_not_notified(): void
    {
        Notification::fake();

        $this->user->update(['email' => null]);

        $this->placeOrder();

        Notification::assertNothingSent();
    }

    public function test_cancellation_by_student_sends_one_letter(): void
    {
        $order = $this->placeOrder();

        Notification::fake();

        app(CancellationService::class)->cancelLine($order->lines()->firstOrFail(), $this->user);

        Notification::assertSentTo(
            $this->user,
            OrderLinesCancelled::class,
            fn (OrderLinesCancelled $notification): bool => $notification->byAdministrator === false,
        );
        Notification::assertCount(1);
    }

    public function test_cancelling_a_whole_day_sends_a_single_letter(): void
    {
        $water = Dish::create(['supplier_id' => $this->supplier->id, 'name' => 'Вода', 'price' => 15]);
        $extras = MenuDay::query()->firstOrFail()->sections()->create([
            'type' => MenuSectionType::Extra,
            'title' => 'Додатково',
            'sort' => 1,
        ]);
        $extras->sectionDishes()->create(['dish_id' => $water->id, 'sort' => 0]);

        $cart = app(CartService::class);
        $cart->add($cart->for($this->student), $this->complex, $this->cutlet->id);
        $cart->add($cart->for($this->student), $extras, $water->id, 2);
        app(OrderService::class)->placeFromCart($this->student);

        Notification::fake();

        app(CancellationService::class)->cancelDay(
            $this->student,
            $this->supplier->id,
            self::SERVICE_DATE,
            $this->user,
        );

        // Дві страви — але лист один.
        Notification::assertCount(1);
        Notification::assertSentTo(
            $this->user,
            OrderLinesCancelled::class,
            fn (OrderLinesCancelled $notification): bool => $notification->lines->count() === 2,
        );
    }

    public function test_admin_cancellation_letter_carries_the_reason(): void
    {
        $order = $this->placeOrder();

        $admin = User::create([
            'name' => 'Адміністратор',
            'email' => 'admin@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]);

        Notification::fake();

        app(CancellationService::class)->cancelLine(
            $order->lines()->firstOrFail(),
            $admin,
            reason: 'Карантин',
            bypassDeadline: true,
        );

        Notification::assertSentTo(
            $this->user,
            OrderLinesCancelled::class,
            fn (OrderLinesCancelled $notification): bool => $notification->byAdministrator
                && $notification->reason === 'Карантин',
        );
    }

    public function test_reminder_goes_only_inside_the_window(): void
    {
        Notification::fake();

        // Дедлайн — неділя 16.08, 09:00. Нагадування за 3 години.
        Setting::put('deadline_reminder_hours', '3');

        CarbonImmutable::setTestNow('2026-08-16 04:00:00');
        $this->artisan('school:send-deadline-reminders')->assertSuccessful();
        Notification::assertNothingSent();

        CarbonImmutable::setTestNow('2026-08-16 07:00:00');
        $this->artisan('school:send-deadline-reminders')->assertSuccessful();
        Notification::assertSentTo($this->user, DeadlineReminder::class);
    }

    public function test_reminder_is_not_sent_to_those_who_already_ordered(): void
    {
        $this->placeOrder();

        Notification::fake();

        CarbonImmutable::setTestNow('2026-08-16 07:00:00');
        $this->artisan('school:send-deadline-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_reminder_is_not_repeated_for_the_same_date(): void
    {
        CarbonImmutable::setTestNow('2026-08-16 07:00:00');

        // Перший запуск — лист іде і потрапляє в журнал відправок.
        $this->artisan('school:send-deadline-reminders')->assertSuccessful();
        $this->assertSame(1, NotificationLog::query()->where('event', 'deadline_reminder')->count());

        Notification::fake();

        $this->artisan('school:send-deadline-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_reminders_can_be_switched_off(): void
    {
        Notification::fake();

        Setting::put('deadline_reminder_enabled', '0');

        CarbonImmutable::setTestNow('2026-08-16 07:00:00');
        $this->artisan('school:send-deadline-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_sent_notification_is_written_to_the_log(): void
    {
        $this->placeOrder();

        $log = NotificationLog::query()->firstOrFail();

        $this->assertSame('order_placed', $log->event);
        $this->assertSame('mail', $log->channel);
        $this->assertSame('mama@example.com', $log->recipient);
        $this->assertSame($this->student->id, $log->student_id);
    }

    private function placeOrder(): \App\Models\Order
    {
        $cart = app(CartService::class);
        $cart->add($cart->for($this->student), $this->complex, $this->cutlet->id);

        return app(OrderService::class)->placeFromCart($this->student);
    }
}
