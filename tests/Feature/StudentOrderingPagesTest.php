<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Models\CartItem;
use App\Models\DeadlineRule;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\MenuSection;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentOrderingPagesTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_DATE = '2026-08-17';   // понеділок

    private Supplier $supplier;

    private User $user;

    private Student $student;

    private Dish $cutlet;

    private Dish $water;

    private MenuSection $complex;

    private MenuSection $extras;

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
            'consent_at' => now(),
            'consent_ip' => '127.0.0.1',
        ]);

        $this->cutlet = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Куряча котлета',
            'price' => 60,
        ]);

        $this->water = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Вода 0,5 л',
            'price' => 15,
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
            'price' => 60,
            'sort' => 0,
        ]);
        $this->complex->sectionDishes()->create(['dish_id' => $this->cutlet->id, 'sort' => 0]);

        $this->extras = $menuDay->sections()->create([
            'type' => MenuSectionType::Extra,
            'title' => 'Додатково',
            'sort' => 1,
        ]);
        $this->extras->sectionDishes()->create(['dish_id' => $this->water->id, 'sort' => 0]);

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_menu_page_shows_dishes_and_deadline(): void
    {
        $this->get(route('menu', $this->supplier->slug))
            ->assertOk()
            ->assertSee('Куряча котлета')
            ->assertSee('Комплекс №1')
            ->assertSee('Замовити можна до');
    }

    public function test_unpublished_day_is_hidden_from_the_menu(): void
    {
        MenuDay::query()->firstOrFail()->update(['published_at' => null]);

        $this->get(route('menu', $this->supplier->slug))
            ->assertOk()
            ->assertDontSee('Куряча котлета');
    }

    public function test_whole_day_is_added_to_the_cart_at_once(): void
    {
        $this->post(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), [
            'complex_qty' => [$this->complex->id => 1],
            'qty' => [
                $this->extras->id => [$this->water->id => 2],
            ],
        ])->assertRedirect();

        $items = CartItem::query()->get();

        $this->assertCount(2, $items);
        $this->assertSame(2, $items->firstWhere('dish_id', $this->water->id)->quantity);
    }

    public function test_dish_with_zero_quantity_is_not_added(): void
    {
        $this->post(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), [
            'complex_qty' => [$this->complex->id => 0],
            'qty' => [
                $this->extras->id => [$this->water->id => 1],
            ],
        ])->assertRedirect();

        $this->assertCount(1, CartItem::query()->get());
        $this->assertNull(CartItem::query()->whereNull('dish_id')->first());
    }

    public function test_empty_selection_reports_an_error(): void
    {
        $this->post(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), [
            'complex_qty' => [$this->complex->id => 0],
        ])->assertSessionHas('error');

        $this->assertCount(0, CartItem::query()->get());
    }

    public function test_cart_page_groups_by_supplier_and_date(): void
    {
        $this->post(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), [
            'complex_qty' => [$this->complex->id => 1],
        ]);

        $this->get(route('cart'))
            ->assertOk()
            ->assertSee('Смачно')
            ->assertSee('Комплекс №1')
            ->assertSee('60,00');
    }

    public function test_order_is_placed_from_the_cart_page(): void
    {
        $this->post(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), [
            'complex_qty' => [$this->complex->id => 1],
        ]);

        $this->post(route('orders.store'))
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('status');

        $this->assertSame(1, Order::query()->count());
        $this->assertCount(0, CartItem::query()->get());
    }

    public function test_orders_page_shows_the_week(): void
    {
        $this->placeOrder();

        $this->get(route('orders.index', ['week' => self::SERVICE_DATE]))
            ->assertOk()
            ->assertSee('Комплекс №1')
            ->assertSee('Скасувати день');
    }

    public function test_student_is_told_where_notifications_go(): void
    {
        $this->user->update(['email' => 'mariia@example.com']);

        foreach ([route('cart'), route('orders.index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('mariia@example.com')
                ->assertSee('Налаштувати');
        }
    }

    public function test_student_without_email_is_warned(): void
    {
        $this->user->update(['email' => null]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertSee('Сповіщення нікуди не надходять');
    }

    public function test_hint_disappears_once_telegram_is_connected(): void
    {
        $this->user->update(['email' => 'mariia@example.com']);

        $this->student->telegramLinks()->create([
            'chat_id' => '100200',
            'is_active' => true,
            'linked_at' => now(),
        ]);

        $this->get(route('orders.index'))
            ->assertOk()
            ->assertDontSee('Налаштувати');
    }

    public function test_order_line_keys_are_integers(): void
    {
        // На хостингу PDO віддавав ключі рядками, і строге "1" === 1 у перевірці
        // власності давало 403 на скасуванні власної ж страви.
        $order = $this->placeOrder();
        $line = $order->lines()->firstOrFail();

        $this->assertIsInt($line->student_id);
        $this->assertIsInt($line->order_id);
        $this->assertIsInt($line->supplier_id);
    }

    public function test_day_without_a_published_menu_reads_as_a_day_off(): void
    {
        // У тижні заповнений лише понеділок — решта днів меню не мають.
        // «Харчування не замовлено» там вводило б в оману: замовляти нема чого.
        $this->placeOrder();

        $this->get(route('orders.index', ['week' => self::SERVICE_DATE]))
            ->assertOk()
            ->assertSee('Вихідний')
            ->assertDontSee('Харчування не замовлено');
    }

    public function test_working_day_without_an_order_still_says_so(): void
    {
        MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => '2026-08-18',
            'is_working_day' => true,
            'published_at' => now(),
        ]);

        $this->placeOrder();

        $this->get(route('orders.index', ['week' => self::SERVICE_DATE]))
            ->assertOk()
            ->assertSee('Харчування не замовлено');
    }

    public function test_holiday_is_named_on_the_orders_page(): void
    {
        \App\Models\NonWorkingDay::create([
            'date' => '2026-08-18',
            'title' => 'День Незалежності',
        ]);

        $this->placeOrder();

        $this->get(route('orders.index', ['week' => self::SERVICE_DATE]))
            ->assertOk()
            ->assertSee('День Незалежності');
    }

    public function test_line_is_cancelled_from_the_orders_page(): void
    {
        $order = $this->placeOrder();
        $line = $order->lines()->firstOrFail();

        $this->delete(route('orders.cancel-line', $line))->assertRedirect();

        $this->assertSame(OrderLineStatus::Cancelled, $line->fresh()->status);
    }

    public function test_cancelling_after_the_deadline_shows_a_message(): void
    {
        $order = $this->placeOrder();
        $line = $order->lines()->firstOrFail();

        CarbonImmutable::setTestNow('2026-08-16 10:00:00');

        $this->delete(route('orders.cancel-line', $line))
            ->assertSessionHas('error');

        $this->assertSame(OrderLineStatus::Active, $line->fresh()->status);
    }

    public function test_student_cannot_touch_another_students_line(): void
    {
        $order = $this->placeOrder();
        $line = $order->lines()->firstOrFail();

        $otherUser = User::create([
            'name' => 'Петренко Іван',
            'login' => 'petrenko.ivan',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        Student::create([
            'user_id' => $otherUser->id,
            'full_name' => 'Петренко Іван',
            'consent_at' => now(),
            'consent_ip' => '127.0.0.1',
        ]);

        $this->actingAs($otherUser)
            ->delete(route('orders.cancel-line', $line))
            ->assertForbidden();

        $this->assertSame(OrderLineStatus::Active, $line->fresh()->status);
    }

    public function test_student_cannot_touch_another_students_cart_item(): void
    {
        $this->post(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), [
            'complex_qty' => [$this->complex->id => 1],
        ]);

        $item = CartItem::query()->firstOrFail();

        $otherUser = User::create([
            'name' => 'Петренко Іван',
            'login' => 'petrenko.ivan',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        Student::create([
            'user_id' => $otherUser->id,
            'full_name' => 'Петренко Іван',
            'consent_at' => now(),
            'consent_ip' => '127.0.0.1',
        ]);

        $this->actingAs($otherUser)
            ->delete(route('cart.destroy-item', $item))
            ->assertForbidden();

        $this->assertSame(1, CartItem::query()->count());
    }

    private function placeOrder(): Order
    {
        $this->post(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), [
            'complex_qty' => [$this->complex->id => 1],
        ]);

        $this->post(route('orders.store'));

        return Order::query()->firstOrFail();
    }
}
