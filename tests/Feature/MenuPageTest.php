<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Models\DeadlineRule;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Меню на 14 днів має читатися з одного екрана: розгорнутий лише найближчий
 * день, що приймає замовлення, решта — згорнуті до шапки.
 */
class MenuPageTest extends TestCase
{
    use RefreshDatabase;

    private const CLOSED_DATE = '2026-08-10';   // понеділок, дедлайн уже минув

    private const NEXT_OPEN_DATE = '2026-08-12';   // середа

    private const LATER_OPEN_DATE = '2026-08-13';   // четвер

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-08-10 10:00:00');

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        foreach ([1, 3, 4] as $weekday) {
            DeadlineRule::create([
                'supplier_id' => $this->supplier->id,
                'weekday' => $weekday,
                'order_offset_days' => 1,
                'order_time' => '09:00:00',
                'cancel_offset_days' => 1,
                'cancel_time' => '09:00:00',
            ]);
        }

        foreach ([self::CLOSED_DATE, self::NEXT_OPEN_DATE, self::LATER_OPEN_DATE] as $date) {
            $this->menuDay($date);
        }
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_only_the_nearest_open_day_is_expanded(): void
    {
        $days = $this->renderedDays();

        $this->assertSame(
            [
                'понеділок, 10.08' => false,
                'середа, 12.08' => true,
                'четвер, 13.08' => false,
            ],
            $days,
        );
    }

    public function test_day_with_a_passed_deadline_stays_collapsed(): void
    {
        $card = $this->dayCard('понеділок, 10.08');

        $this->assertStringContainsString('Приймання завершено', $card);
        $this->assertStringNotContainsString('Додати цей день у кошик', $card);
        $this->assertFalse($this->isExpanded($card));
    }

    public function test_day_is_split_into_main_dishes_and_extras(): void
    {
        $card = $this->dayCard('середа, 12.08');

        $this->assertStringContainsString('Комплекс', $card);
        $this->assertStringContainsString('Додатково', $card);
        // Кожна страва показується рівно в одному клікабельному зображенні:
        // комплексна страва в основних, напій — у додаткових.
        $this->assertSame(1, substr_count($card, 'data-dish-name="Куряча котлета '.self::NEXT_OPEN_DATE.'"'));
        $this->assertSame(1, substr_count($card, 'data-dish-name="Вода '.self::NEXT_OPEN_DATE.'"'));

        // Дві колонки на широкому екрані, права — з власною прокруткою.
        $this->assertStringContainsString('md:grid-cols-2', $card);
        $this->assertStringContainsString('overflow-y-auto', $card);
    }

    public function test_day_total_starts_from_the_complex_and_is_wired_for_live_updates(): void
    {
        $card = $this->dayCard('середа, 12.08');

        // Комплекс приходить відміченим — сервер одразу показує його суму.
        $this->assertStringContainsString('Разом за день', $card);
        $this->assertStringContainsString('60,00 грн', $card);

        // Далі суму перераховує JS: йому потрібні форма, поле виводу й ціни.
        $this->assertStringContainsString('data-day-form', $card);
        $this->assertStringContainsString('data-day-total', $card);
        $this->assertStringContainsString('data-price="60.00"', $card);
        $this->assertStringContainsString('data-price="15.00"', $card);
    }

    public function test_closed_day_has_no_total_block(): void
    {
        $card = $this->dayCard('понеділок, 10.08');

        $this->assertStringNotContainsString('Разом за день', $card);
        $this->assertStringNotContainsString('data-day-form', $card);
    }

    /**
     * Шапка дня → чи розгорнутий блок.
     *
     * @return array<string, bool>
     */
    private function renderedDays(): array
    {
        $days = [];

        foreach ($this->cards() as $card) {
            $days[$this->headerOf($card)] = $this->isExpanded($card);
        }

        return $days;
    }

    private function dayCard(string $header): string
    {
        foreach ($this->cards() as $card) {
            if ($this->headerOf($card) === $header) {
                return $card;
            }
        }

        $this->fail("У меню немає дня «{$header}».");
    }

    /** @return array<int, string> */
    private function cards(): array
    {
        $html = $this->get(route('menu', $this->supplier->slug))
            ->assertOk()
            ->getContent();

        // Тільки картки днів: у шапці на <details> зроблені й випадні меню.
        preg_match_all('/<details\b(?![^>]*data-header-menu).*?<\/details>/s', $html, $matches);

        return $matches[0];
    }

    private function headerOf(string $card): string
    {
        preg_match('/<h2[^>]*>(.*?)<\/h2>/s', $card, $matches);

        return trim($matches[1] ?? '');
    }

    private function isExpanded(string $card): bool
    {
        preg_match('/<details\b([^>]*)>/', $card, $matches);

        return str_contains($matches[1] ?? '', 'open');
    }

    private function menuDay(string $date): void
    {
        $menuDay = MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => $date,
            'is_working_day' => true,
            'published_at' => now(),
        ]);

        $complex = $menuDay->sections()->create([
            'type' => MenuSectionType::Complex,
            'title' => 'Комплекс №1',
            'price' => 60,
            'sort' => 0,
        ]);

        $complex->sectionDishes()->create([
            'dish_id' => Dish::create([
                'supplier_id' => $this->supplier->id,
                'name' => "Куряча котлета {$date}",
                'price' => 60,
            ])->id,
            'sort' => 0,
        ]);

        $extras = $menuDay->sections()->create([
            'type' => MenuSectionType::Extra,
            'title' => 'Напої',
            'sort' => 1,
        ]);

        $extras->sectionDishes()->create([
            'dish_id' => Dish::create([
                'supplier_id' => $this->supplier->id,
                'name' => "Вода {$date}",
                'price' => 15,
            ])->id,
            'sort' => 0,
        ]);
    }
}
