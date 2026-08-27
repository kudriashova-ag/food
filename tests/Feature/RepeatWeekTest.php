<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Models\CartItem;
use App\Models\DeadlineRule;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Orders\CartService;
use App\Services\Orders\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepeatWeekTest extends TestCase
{
    use RefreshDatabase;

    /** Понеділок минулого тижня. */
    private const SOURCE_MONDAY = '2026-08-10';

    /** Понеділок наступного тижня. */
    private const TARGET_MONDAY = '2026-08-17';

    private Supplier $supplier;

    private Student $student;

    private User $user;

    private Dish $cutlet;

    protected function setUp(): void
    {
        parent::setUp();

        // Замовляємо заздалегідь, щоб дедлайни цільового тижня були відкриті.
        CarbonImmutable::setTestNow('2026-08-05 10:00:00');

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        foreach (range(1, 5) as $weekday) {
            DeadlineRule::create([
                'supplier_id' => $this->supplier->id,
                'weekday' => $weekday,
                'order_offset_days' => 1,
                'order_time' => '09:00:00',
                'cancel_offset_days' => 1,
                'cancel_time' => '09:00:00',
            ]);
        }

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

        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_last_week_set_is_moved_to_the_next_one(): void
    {
        $this->menuDay(self::SOURCE_MONDAY, $this->cutlet);
        $this->menuDay(self::TARGET_MONDAY, $this->cutlet);

        $this->orderOn(self::SOURCE_MONDAY, quantity: 2);

        $result = app(CartService::class)->repeatWeek($this->student, self::SOURCE_MONDAY, self::TARGET_MONDAY);

        $this->assertSame(1, $result['added']);
        $this->assertSame([], $result['unavailable']);

        $item = CartItem::query()->firstOrFail();

        $this->assertSame(self::TARGET_MONDAY, $item->service_date->toDateString());
        $this->assertSame(2, $item->quantity);
    }

    public function test_missing_dish_is_reported_instead_of_being_skipped_silently(): void
    {
        $this->menuDay(self::SOURCE_MONDAY, $this->cutlet);

        // На цільовому тижні в меню інша страва.
        $fish = Dish::create(['supplier_id' => $this->supplier->id, 'name' => 'Риба', 'price' => 70]);
        $this->menuDay(self::TARGET_MONDAY, $fish);

        $this->orderOn(self::SOURCE_MONDAY);

        $result = app(CartService::class)->repeatWeek($this->student, self::SOURCE_MONDAY, self::TARGET_MONDAY);

        $this->assertSame(0, $result['added']);
        $this->assertCount(1, $result['unavailable']);
        $this->assertStringContainsString('Куряча котлета', $result['unavailable'][0]);
    }

    public function test_dish_is_matched_by_name_not_by_id(): void
    {
        $this->menuDay(self::SOURCE_MONDAY, $this->cutlet);
        $this->orderOn(self::SOURCE_MONDAY);

        // Постачальник перестворив страву: назва та сама, id інший.
        $recreated = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Куряча котлета',
            'price' => 65,
        ]);
        $this->menuDay(self::TARGET_MONDAY, $recreated);

        $result = app(CartService::class)->repeatWeek($this->student, self::SOURCE_MONDAY, self::TARGET_MONDAY);

        $this->assertSame(1, $result['added']);
        $this->assertSame($recreated->id, CartItem::query()->firstOrFail()->dish_id);
    }

    public function test_weekday_is_preserved(): void
    {
        // Середа минулого тижня → середа наступного.
        $this->menuDay('2026-08-12', $this->cutlet);
        $this->menuDay('2026-08-19', $this->cutlet);

        $this->orderOn('2026-08-12');

        app(CartService::class)->repeatWeek($this->student, self::SOURCE_MONDAY, self::TARGET_MONDAY);

        $this->assertSame('2026-08-19', CartItem::query()->firstOrFail()->service_date->toDateString());
    }

    public function test_cancelled_lines_are_not_repeated(): void
    {
        $this->menuDay(self::SOURCE_MONDAY, $this->cutlet);
        $this->menuDay(self::TARGET_MONDAY, $this->cutlet);

        $order = $this->orderOn(self::SOURCE_MONDAY);
        $order->lines()->firstOrFail()->update(['status' => \App\Enums\OrderLineStatus::Cancelled]);

        $result = app(CartService::class)->repeatWeek($this->student, self::SOURCE_MONDAY, self::TARGET_MONDAY);

        $this->assertSame(0, $result['added']);
        $this->assertSame([], $result['unavailable']);
    }

    public function test_button_moves_items_and_redirects_to_the_cart(): void
    {
        $this->menuDay(self::SOURCE_MONDAY, $this->cutlet);
        $this->menuDay(self::TARGET_MONDAY, $this->cutlet);

        $this->orderOn(self::SOURCE_MONDAY);

        $this->post(route('orders.repeat-week'), ['source' => self::SOURCE_MONDAY])
            ->assertRedirect(route('cart'))
            ->assertSessionHas('status');

        $this->assertSame(1, CartItem::query()->count());
    }

    private function menuDay(string $date, Dish $dish): MenuDay
    {
        $menuDay = MenuDay::query()->firstOrCreate(
            ['supplier_id' => $this->supplier->id, 'date' => $date],
            ['is_working_day' => true, 'published_at' => now()],
        );

        $section = $menuDay->sections()->firstOrCreate(
            ['title' => 'Комплекс №1'],
            ['type' => MenuSectionType::Complex, 'sort' => 0],
        );

        $section->sectionDishes()->firstOrCreate(['dish_id' => $dish->id], ['sort' => 0]);

        return $menuDay;
    }

    private function orderOn(string $date, int $quantity = 1): \App\Models\Order
    {
        $section = MenuDay::query()
            ->where('supplier_id', $this->supplier->id)
            ->whereDate('date', $date)
            ->firstOrFail()
            ->sections()
            ->firstOrFail();

        $dishId = $section->sectionDishes()->firstOrFail()->dish_id;

        $cart = app(CartService::class);
        $cart->add($cart->for($this->student), $section, $dishId, $quantity);

        return app(OrderService::class)->placeFromCart($this->student);
    }
}
