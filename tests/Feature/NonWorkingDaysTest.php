<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Exceptions\MenuUnavailableException;
use App\Filament\Resources\NonWorkingDays\Pages\ListNonWorkingDays;
use App\Models\DeadlineRule;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\MenuTemplate;
use App\Models\NonWorkingDay;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Menu\HolidayRangeService;
use App\Services\Menu\MenuTemplateService;
use App\Services\Orders\CartService;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NonWorkingDaysTest extends TestCase
{
    use RefreshDatabase;

    /** Понеділок. */
    private const MONDAY = '2026-08-17';

    private Supplier $supplier;

    private Dish $cutlet;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-10 10:00:00');

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

        $this->cutlet = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Куряча котлета',
            'price' => 60,
        ]);

        $user = User::create([
            'name' => 'Іваненко Марія',
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        $this->student = Student::create([
            'user_id' => $user->id,
            'full_name' => 'Іваненко Марія',
            'school_class_id' => SchoolClass::create([
                'grade' => 5, 'letter' => 'А', 'academic_year' => 2026,
            ])->id,
            'consent_at' => now(),
            'consent_ip' => '127.0.0.1',
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_template_creates_a_holiday_as_a_closed_day(): void
    {
        NonWorkingDay::create(['date' => self::MONDAY, 'title' => 'День Незалежності']);

        $template = $this->weeklyTemplate();

        app(MenuTemplateService::class)->apply($template, self::MONDAY, '2026-08-18');

        $holiday = MenuDay::query()->whereDate('date', self::MONDAY)->firstOrFail();
        $ordinary = MenuDay::query()->whereDate('date', '2026-08-18')->firstOrFail();

        // Свято — порожній неробочий день, попри заповнений шаблон.
        $this->assertFalse($holiday->is_working_day);
        $this->assertCount(0, $holiday->sections);

        // Наступний день заповнився як звичайно.
        $this->assertTrue($ordinary->is_working_day);
        $this->assertCount(1, $ordinary->sections);
    }

    public function test_copy_week_also_respects_holidays(): void
    {
        $source = MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => '2026-08-10',
            'is_working_day' => true,
            'published_at' => now(),
        ]);

        $section = $source->sections()->create([
            'type' => MenuSectionType::Complex,
            'title' => 'Комплекс №1',
            'sort' => 0,
        ]);
        $section->sectionDishes()->create(['dish_id' => $this->cutlet->id, 'sort' => 0]);

        NonWorkingDay::create(['date' => self::MONDAY, 'title' => 'Свято']);

        app(MenuTemplateService::class)->copyWeek($this->supplier, '2026-08-10', self::MONDAY);

        $copy = MenuDay::query()->whereDate('date', self::MONDAY)->firstOrFail();

        $this->assertFalse($copy->is_working_day);
        $this->assertCount(0, $copy->sections);
    }

    public function test_holiday_hides_an_already_published_menu_from_students(): void
    {
        $this->publishedMenuDay(self::MONDAY);

        $this->assertCount(1, MenuDay::query()->visibleToStudents()->get());

        NonWorkingDay::create(['date' => self::MONDAY, 'title' => 'Свято']);

        $this->assertCount(0, MenuDay::query()->visibleToStudents()->get());
    }

    public function test_ordering_on_a_holiday_is_rejected(): void
    {
        $menuDay = $this->publishedMenuDay(self::MONDAY);
        $section = $menuDay->sections()->firstOrFail();

        NonWorkingDay::create(['date' => self::MONDAY, 'title' => 'Свято']);

        $cart = app(CartService::class);

        $this->expectException(MenuUnavailableException::class);

        $cart->add($cart->for($this->student), $section, $this->cutlet->id);
    }

    public function test_range_creates_days_and_closes_existing_menus(): void
    {
        $this->publishedMenuDay('2026-12-28');

        $result = app(HolidayRangeService::class)->createRange(
            from: '2026-12-28',
            to: '2027-01-08',
            title: 'Зимові канікули',
            skipWeekends: true,
        );

        // 28.12–08.01 без вихідних — 10 робочих днів.
        $this->assertSame(10, $result['created']);
        $this->assertSame(1, $result['closed']);
        $this->assertFalse(MenuDay::query()->whereDate('date', '2026-12-28')->firstOrFail()->is_working_day);

        // Вихідні в календар не потрапили.
        $this->assertFalse(NonWorkingDay::isHoliday('2027-01-02'));
    }

    public function test_range_can_include_weekends(): void
    {
        $result = app(HolidayRangeService::class)->createRange(
            from: '2026-08-15',   // субота
            to: '2026-08-16',     // неділя
            title: 'Свято',
            skipWeekends: false,
        );

        $this->assertSame(2, $result['created']);
    }

    public function test_repeated_range_does_not_duplicate_dates(): void
    {
        $service = app(HolidayRangeService::class);

        $service->createRange(from: self::MONDAY, to: self::MONDAY, title: 'Свято');
        $result = $service->createRange(from: self::MONDAY, to: self::MONDAY, title: 'Свято');

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, NonWorkingDay::query()->count());
    }

    public function test_admin_adds_a_holiday_range_from_the_panel(): void
    {
        Filament::setCurrentPanel('admin');

        $this->actingAs(User::create([
            'name' => 'Адміністратор',
            'email' => 'admin@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]));

        Livewire::test(ListNonWorkingDays::class)
            ->callAction('addRange', data: [
                'from' => '2026-10-26',
                'to' => '2026-10-30',
                'title' => 'Осінні канікули',
                'skip_weekends' => true,
                'close_published_days' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(5, NonWorkingDay::query()->count());
        $this->assertSame('Осінні канікули', NonWorkingDay::query()->firstOrFail()->title);
    }

    private function weeklyTemplate(): MenuTemplate
    {
        $template = MenuTemplate::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Основний тиждень',
            'cycle_length' => 7,
        ]);

        $service = app(MenuTemplateService::class);
        $service->ensureDays($template);

        foreach (range(1, 5) as $dayIndex) {
            $section = $template->days()->where('day_index', $dayIndex)->firstOrFail()->sections()->create([
                'type' => MenuSectionType::Complex,
                'title' => 'Комплекс №1',
                'sort' => 0,
            ]);

            $section->sectionDishes()->create(['dish_id' => $this->cutlet->id, 'sort' => 0]);
        }

        return $template->fresh();
    }

    private function publishedMenuDay(string $date): MenuDay
    {
        $menuDay = MenuDay::query()->firstOrCreate(
            ['supplier_id' => $this->supplier->id, 'date' => $date],
            ['is_working_day' => true, 'published_at' => now()],
        );

        if (! $menuDay->sections()->exists()) {
            $section = $menuDay->sections()->create([
                'type' => MenuSectionType::Complex,
                'title' => 'Комплекс №1',
                'sort' => 0,
            ]);

            $section->sectionDishes()->create(['dish_id' => $this->cutlet->id, 'sort' => 0]);
        }

        return $menuDay->fresh();
    }
}
