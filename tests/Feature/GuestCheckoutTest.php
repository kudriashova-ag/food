<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Models\Cart;
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

/**
 * Вхід просимо тільки на оформленні: меню й кошик відкриті гостю,
 * а зібраний до входу кошик переноситься в кошик учня.
 */
class GuestCheckoutTest extends TestCase
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
            'sort' => 0,
        ]);
        $this->complex->sectionDishes()->create(['dish_id' => $this->cutlet->id, 'sort' => 0]);

        $this->extras = $menuDay->sections()->create([
            'type' => MenuSectionType::Extra,
            'title' => 'Додатково',
            'sort' => 1,
        ]);
        $this->extras->sectionDishes()->create(['dish_id' => $this->water->id, 'sort' => 0]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_guest_opens_the_menu(): void
    {
        $this->get(route('menu', $this->supplier->slug))
            ->assertOk()
            ->assertSee('Куряча котлета');
    }

    public function test_guest_collects_the_cart_without_logging_in(): void
    {
        $this->addDayToCart();

        $this->get(route('cart'))
            ->assertOk()
            ->assertSee('Куряча котлета')
            ->assertSee('Увійти й оформити замовлення')
            ->assertDontSee('Підтвердити замовлення');

        $cart = Cart::query()->firstOrFail();

        $this->assertNull($cart->student_id);
        $this->assertNotNull($cart->session_token);
        $this->assertSame(1, CartItem::query()->count());
    }

    public function test_guest_cannot_place_an_order(): void
    {
        $this->addDayToCart();

        $this->post(route('orders.store'))->assertRedirect(route('login'));

        $this->assertSame(0, Order::query()->count());
    }

    public function test_guest_cart_moves_to_the_student_after_login(): void
    {
        $this->addDayToCart();
        $this->get(route('cart'))->assertOk();

        $this->post('/login', [
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
        ])->assertRedirect(route('cart'));

        $this->assertAuthenticatedAs($this->user);

        $cart = Cart::query()->sole();

        $this->assertSame($this->student->id, $cart->student_id);
        $this->assertSame(1, $cart->items()->count());

        $this->get(route('cart'))
            ->assertOk()
            ->assertSee('Підтвердити замовлення');
    }

    public function test_moved_cart_stays_editable_after_login(): void
    {
        // Раніше токен губився під час session()->regenerate(), гостьовий кошик
        // лишався окремим — і зміна кількості впиралася в 403.
        $this->addDayToCart();

        $this->post('/login', [
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
        ])->assertRedirect(route('cart'));

        $this->assertSame(1, Cart::query()->count(), 'Гостьовий кошик мав переїхати, а не лишитися другим');

        $item = CartItem::query()->sole();

        $this->patch(route('cart.update-item', $item), ['quantity' => 3])
            ->assertRedirect();

        $this->assertSame(3, $item->fresh()->quantity);

        $this->delete(route('cart.destroy-item', $item))->assertRedirect();
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_day_is_added_without_reloading_the_page(): void
    {
        // Меню додає день через fetch — у відповідь потрібен свіжий підсумок
        // кошика, щоб оновити шапку й нижню панель на місці.
        $response = $this->postJson(
            route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]),
            ['qty' => [$this->complex->id => [$this->cutlet->id => 2]]],
        );

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'cart' => ['count' => 2, 'total' => 120],
            ])
            ->assertJsonPath('message', fn (string $m): bool => str_contains($m, '17.08.2026'));
    }

    public function test_empty_selection_answers_with_an_error_for_fetch(): void
    {
        $this->postJson(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), ['qty' => []])
            ->assertStatus(422)
            ->assertJson(['ok' => false])
            ->assertJsonPath('cart.count', 0);

        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_form_without_javascript_still_redirects_back(): void
    {
        $this->from(route('menu', $this->supplier->slug))
            ->post(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), [
                'qty' => [$this->complex->id => [$this->cutlet->id => 1]],
            ])
            ->assertRedirect(route('menu', $this->supplier->slug))
            ->assertSessionHas('status');
    }

    public function test_cart_item_keys_are_integers(): void
    {
        // На хостингу PDO віддавав cart_id рядком, і строге порівняння "1" === 1
        // у перевірці власності кошика давало 403 на власні ж позиції.
        $this->actingAs($this->user)->post(
            route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]),
            ['qty' => [$this->complex->id => [$this->cutlet->id => 1]]],
        );

        $item = CartItem::query()->sole();

        $this->assertIsInt($item->cart_id);
        $this->assertIsInt($item->supplier_id);
        $this->assertIsInt($item->dish_id);
        $this->assertIsInt($item->menu_section_id);
    }

    public function test_moved_cart_merges_with_what_the_student_already_had(): void
    {
        // Учень поклав воду під логіном, вийшов і доклав котлету вже гостем.
        $this->actingAs($this->user)->post(
            route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]),
            ['qty' => [$this->extras->id => [$this->water->id => 1]]],
        );

        $this->post(route('logout'));

        $this->addDayToCart();

        $this->post('/login', [
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
        ])->assertRedirect(route('cart'));

        $cart = Cart::query()->sole();
        $items = $cart->items()->get();

        $this->assertSame($this->student->id, $cart->student_id);
        $this->assertCount(2, $items);
        $this->assertSame(1, $items->firstWhere('dish_id', $this->cutlet->id)->quantity);
        $this->assertSame(1, $items->firstWhere('dish_id', $this->water->id)->quantity);
    }

    public function test_guest_order_is_placed_right_after_login(): void
    {
        $this->addDayToCart();

        $this->post('/login', [
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
        ]);

        $this->post(route('orders.store'))
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('status');

        $order = Order::query()->sole();

        $this->assertSame($this->student->id, $order->student_id);
        $this->assertSame('60.00', (string) $order->total_amount);
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_guest_cannot_touch_a_cart_that_is_not_his(): void
    {
        $this->actingAs($this->user)->post(
            route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]),
            ['qty' => [$this->complex->id => [$this->cutlet->id => 1]]],
        );

        $item = CartItem::query()->firstOrFail();

        $this->post(route('logout'));

        $this->delete(route('cart.destroy-item', $item))->assertForbidden();

        $this->assertSame(1, CartItem::query()->count());
    }

    public function test_abandoned_guest_cart_is_pruned(): void
    {
        $this->addDayToCart();

        $cart = Cart::query()->sole();
        $cart->forceFill(['updated_at' => now()->subDays(60)])->save();
        $cart->items()->update(['updated_at' => now()->subDays(60)]);

        $this->artisan('model:prune', ['--model' => [Cart::class]])->assertSuccessful();

        $this->assertSame(0, Cart::query()->count());
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_student_cart_is_never_pruned(): void
    {
        $this->actingAs($this->user)->post(
            route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]),
            ['qty' => [$this->complex->id => [$this->cutlet->id => 1]]],
        );

        $cart = Cart::query()->sole();
        $cart->forceFill(['updated_at' => now()->subYear()])->save();
        $cart->items()->update(['updated_at' => now()->subYear()]);

        $this->artisan('model:prune', ['--model' => [Cart::class]])->assertSuccessful();

        $this->assertSame(1, Cart::query()->count());
    }

    private function addDayToCart(): void
    {
        $this->post(route('cart.store-day', [$this->supplier->slug, self::SERVICE_DATE]), [
            'qty' => [$this->complex->id => [$this->cutlet->id => 1]],
        ])->assertRedirect();
    }
}
