<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Exceptions\DeadlinePassedException;
use App\Exceptions\EmptyCartException;
use App\Exceptions\MenuUnavailableException;
use App\Models\Cart;
use App\Models\DeadlineRule;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\MenuSection;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Orders\CancellationService;
use App\Services\Orders\CartService;
use App\Services\Orders\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Понеділок. */
    private const SERVICE_DATE = '2026-08-17';

    private Supplier $supplier;

    private Student $student;

    private User $user;

    private Dish $cutlet;

    private Dish $water;

    private MenuSection $complex;

    private MenuSection $extras;

    private CartService $cart;

    private Cart $cartModel;

    private OrderService $orders;

    private CancellationService $cancellations;

    protected function setUp(): void
    {
        parent::setUp();

        // Замовлення робимо «за тиждень до» дати харчування, щоб дедлайн був відкритий.
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

        $class = SchoolClass::create(['grade' => 5, 'letter' => 'А', 'academic_year' => 2026]);

        $this->student = Student::create([
            'user_id' => $this->user->id,
            'full_name' => 'Іваненко Марія',
            'school_class_id' => $class->id,
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

        $this->cart = app(CartService::class);
        $this->cartModel = $this->cart->for($this->student);
        $this->orders = app(OrderService::class);
        $this->cancellations = app(CancellationService::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_order_freezes_price_and_dish_name(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);

        $order = $this->orders->placeFromCart($this->student);

        // Ціна комплексу змінилася вже після оформлення.
        $this->complex->update(['price' => 90]);
        $this->cutlet->update(['name' => 'Котлета по-новому', 'price' => 80]);

        $line = $order->lines()->firstOrFail();

        $this->assertSame('Комплекс №1: Куряча котлета', $line->dish_name);
        $this->assertSame('60.00', $line->unit_price);
        $this->assertSame('60.00', $order->fresh()->total_amount);
    }

    public function test_quantity_is_free_for_any_dish(): void
    {
        $this->cart->add($this->cartModel, $this->extras, $this->water->id, quantity: 3);

        $order = $this->orders->placeFromCart($this->student);

        $this->assertSame(3, $order->lines()->firstOrFail()->quantity);
        $this->assertSame('45.00', $order->total_amount);
    }

    public function test_choice_section_keeps_only_one_variant(): void
    {
        $menuDay = MenuDay::query()->firstOrFail();

        $soup = Dish::create(['supplier_id' => $this->supplier->id, 'name' => 'Суп', 'price' => 30]);
        $borsch = Dish::create(['supplier_id' => $this->supplier->id, 'name' => 'Борщ', 'price' => 40]);

        $choice = $menuDay->sections()->create([
            'type' => MenuSectionType::Choice,
            'title' => 'Перша страва',
            'sort' => 2,
        ]);
        $choice->sectionDishes()->create(['dish_id' => $soup->id, 'sort' => 0]);
        $choice->sectionDishes()->create(['dish_id' => $borsch->id, 'sort' => 1]);

        $this->cart->add($this->cartModel, $choice, $soup->id);
        $this->cart->add($this->cartModel, $choice, $borsch->id);

        $items = $this->cartModel->items()->where('menu_section_id', $choice->id)->get();

        $this->assertCount(1, $items);
        $this->assertSame($borsch->id, $items->first()->dish_id);
    }

    public function test_order_without_a_main_dish_is_allowed(): void
    {
        // ТЗ, п. 17.2: можна замовити тільки воду.
        $this->cart->add($this->cartModel, $this->extras, $this->water->id, quantity: 2);

        $order = $this->orders->placeFromCart($this->student);

        $this->assertCount(1, $order->lines);
        $this->assertSame('30.00', $order->total_amount);
    }

    public function test_dish_from_another_section_is_rejected(): void
    {
        $this->expectException(MenuUnavailableException::class);

        // extras — секція Extra, вода в ній не належить $this->extras чужій секції.
        $foreignSection = MenuDay::query()->firstOrFail()->sections()->create([
            'type' => MenuSectionType::Extra,
            'title' => 'Інша секція',
            'sort' => 5,
        ]);

        $this->cart->add($this->cartModel, $foreignSection, $this->water->id);
    }

    public function test_complex_without_price_is_rejected(): void
    {
        $this->complex->update(['price' => null]);

        $this->expectException(MenuUnavailableException::class);

        $this->cart->add($this->cartModel, $this->complex, null);
    }

    public function test_unpublished_menu_cannot_be_ordered(): void
    {
        MenuDay::query()->firstOrFail()->update(['published_at' => null]);

        $this->expectException(MenuUnavailableException::class);

        $this->cart->add($this->cartModel, $this->complex, null);
    }

    public function test_ordering_after_the_deadline_is_rejected(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);

        // Дедлайн — неділя 16.08 о 09:00, оформлюємо на годину пізніше.
        CarbonImmutable::setTestNow('2026-08-16 10:00:00');

        $this->expectException(DeadlinePassedException::class);

        $this->orders->placeFromCart($this->student);
    }

    public function test_empty_cart_cannot_be_placed(): void
    {
        $this->expectException(EmptyCartException::class);

        $this->orders->placeFromCart($this->student);
    }

    public function test_cart_is_cleared_after_placing_the_order(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);
        $this->orders->placeFromCart($this->student);

        $this->assertSame(0, $this->cart->count($this->cartModel));
    }

    public function test_order_number_is_unique_per_day(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);
        $first = $this->orders->placeFromCart($this->student);

        // Другий постачальник: те саме замовлення того самого учня в той самий день —
        // повторно замовляти в того самого постачальника на вже замовлений день заборонено.
        $otherSupplier = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        DeadlineRule::create([
            'supplier_id' => $otherSupplier->id,
            'weekday' => 1,
            'order_offset_days' => 1,
            'order_time' => '09:00:00',
            'cancel_offset_days' => 1,
            'cancel_time' => '09:00:00',
        ]);

        $otherMenuDay = MenuDay::create([
            'supplier_id' => $otherSupplier->id,
            'date' => self::SERVICE_DATE,
            'is_working_day' => true,
            'published_at' => now(),
        ]);

        $otherExtras = $otherMenuDay->sections()->create([
            'type' => MenuSectionType::Extra,
            'title' => 'Додатково',
            'sort' => 0,
        ]);
        $otherExtras->sectionDishes()->create(['dish_id' => $this->water->id, 'sort' => 0]);

        $this->cart->add($this->cartModel, $otherExtras, $this->water->id);
        $second = $this->orders->placeFromCart($this->student);

        $this->assertSame('ЗМ-20260810-0001', $first->number);
        $this->assertSame('ЗМ-20260810-0002', $second->number);
    }

    public function test_ordering_the_same_day_twice_is_rejected(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);
        $this->orders->placeFromCart($this->student);

        $this->cart->add($this->cartModel, $this->extras, $this->water->id);

        $this->expectException(MenuUnavailableException::class);
        $this->expectExceptionMessage('вже є оформлене замовлення');

        $this->orders->placeFromCart($this->student);
    }

    public function test_cart_reports_a_day_as_already_ordered(): void
    {
        $this->assertFalse($this->cart->hasOrder($this->student, $this->supplier->id, self::SERVICE_DATE));

        $this->cart->add($this->cartModel, $this->complex, null);
        $this->orders->placeFromCart($this->student);

        $this->assertTrue($this->cart->hasOrder($this->student, $this->supplier->id, self::SERVICE_DATE));
        $this->assertSame(
            [self::SERVICE_DATE],
            $this->cart->datesOrderedFor($this->supplier, $this->student),
        );
    }

    public function test_cancelling_the_whole_day_allows_ordering_it_again(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);
        $this->orders->placeFromCart($this->student);

        $this->cancellations->cancelDay($this->student, $this->supplier->id, self::SERVICE_DATE, $this->user);

        $this->assertFalse($this->cart->hasOrder($this->student, $this->supplier->id, self::SERVICE_DATE));

        $this->cart->add($this->cartModel, $this->extras, $this->water->id);
        $second = $this->orders->placeFromCart($this->student);

        $this->assertSame('ЗМ-20260810-0002', $second->number);
    }

    public function test_cancelling_one_line_keeps_the_rest_active(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);
        $this->cart->add($this->cartModel, $this->extras, $this->water->id, quantity: 2);

        $order = $this->orders->placeFromCart($this->student);
        // Комплекс — рядок з dish_id = null, шукаємо по menu_section_id.
        $complexLine = $order->lines()->where('menu_section_id', $this->complex->id)->firstOrFail();

        $this->cancellations->cancelLine($complexLine, $this->user);

        $this->assertSame(OrderLineStatus::Cancelled, $complexLine->fresh()->status);
        $this->assertSame('30.00', $order->fresh()->total_amount);
        $this->assertCount(1, $order->lines()->active()->get());
    }

    public function test_partial_quantity_can_be_cancelled(): void
    {
        // ТЗ, п. 17.3: замовлено 3 води — скасувати можна одну.
        $this->cart->add($this->cartModel, $this->extras, $this->water->id, quantity: 3);

        $order = $this->orders->placeFromCart($this->student);
        $line = $order->lines()->firstOrFail();

        $this->cancellations->cancelLine($line, $this->user, quantity: 1);

        $this->assertSame(2, $line->fresh()->quantity);
        $this->assertSame(1, $order->lines()->where('status', OrderLineStatus::Cancelled)->sum('quantity'));
        $this->assertSame('30.00', $order->fresh()->total_amount);
    }

    public function test_cancelling_a_day_removes_every_line_of_that_supplier(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);
        $this->cart->add($this->cartModel, $this->extras, $this->water->id, quantity: 2);

        $order = $this->orders->placeFromCart($this->student);

        $cancelled = $this->cancellations->cancelDay(
            $this->student,
            $this->supplier->id,
            self::SERVICE_DATE,
            $this->user,
        );

        $this->assertSame(2, $cancelled);
        $this->assertSame('0.00', $order->fresh()->total_amount);
        $this->assertCount(0, $order->lines()->active()->get());
    }

    public function test_cancelling_after_the_deadline_is_rejected(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);
        $order = $this->orders->placeFromCart($this->student);

        CarbonImmutable::setTestNow('2026-08-16 10:00:00');

        $this->expectException(DeadlinePassedException::class);

        $this->cancellations->cancelLine($order->lines()->firstOrFail(), $this->user);
    }

    public function test_admin_may_cancel_after_the_deadline_with_a_reason(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);
        $order = $this->orders->placeFromCart($this->student);

        CarbonImmutable::setTestNow('2026-08-16 10:00:00');

        $admin = User::create([
            'name' => 'Адміністратор',
            'email' => 'admin@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]);

        $line = $order->lines()->firstOrFail();

        $this->cancellations->cancelLine($line, $admin, reason: 'Дитина захворіла', bypassDeadline: true);

        $line->refresh();

        $this->assertSame(OrderLineStatus::Cancelled, $line->status);
        $this->assertSame('Дитина захворіла', $line->cancel_reason);
        $this->assertSame($admin->id, $line->cancelled_by);
    }

    public function test_cancelled_line_is_not_deleted(): void
    {
        $this->cart->add($this->cartModel, $this->complex, null);
        $order = $this->orders->placeFromCart($this->student);

        $this->cancellations->cancelLine($order->lines()->firstOrFail(), $this->user);

        $this->assertSame(1, Order::query()->firstOrFail()->lines()->count());
    }
}
