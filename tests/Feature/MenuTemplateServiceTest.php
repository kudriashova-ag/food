<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\MenuTemplate;
use App\Models\MenuTemplateDay;
use App\Models\Supplier;
use App\Services\Menu\MenuTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Dish $cutlet;

    private Dish $soup;

    private MenuTemplateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $this->service = new MenuTemplateService();

        $this->cutlet = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Куряча котлета',
            'price' => 60,
        ]);

        $this->soup = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Суп',
            'price' => 30,
        ]);
    }

    public function test_weekly_template_fills_matching_weekdays(): void
    {
        $template = $this->weeklyTemplate();

        // 17.08.2026 — понеділок, 23.08 — неділя.
        $result = $this->service->apply($template, '2026-08-17', '2026-08-23');

        $this->assertSame(7, $result->created);

        $monday = MenuDay::query()->whereDate('date', '2026-08-17')->firstOrFail();
        $this->assertTrue($monday->is_working_day);
        $this->assertCount(1, $monday->sections);
        $this->assertSame('Комплекс №1', $monday->sections->first()->title);
        $this->assertCount(2, $monday->sections->first()->sectionDishes);

        $saturday = MenuDay::query()->whereDate('date', '2026-08-22')->firstOrFail();
        $this->assertFalse($saturday->is_working_day);
        $this->assertCount(0, $saturday->sections);
    }

    public function test_existing_days_are_skipped_unless_overwrite_is_requested(): void
    {
        $template = $this->weeklyTemplate();

        MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => '2026-08-17',
            'is_working_day' => true,
        ]);

        $skipped = $this->service->apply($template, '2026-08-17', '2026-08-18');

        $this->assertSame(1, $skipped->created);
        $this->assertSame(1, $skipped->skipped);
        $this->assertCount(0, MenuDay::query()->whereDate('date', '2026-08-17')->firstOrFail()->sections);

        $overwritten = $this->service->apply($template, '2026-08-17', '2026-08-18', overwrite: true);

        $this->assertSame(2, $overwritten->updated);
        $this->assertCount(1, MenuDay::query()->whereDate('date', '2026-08-17')->firstOrFail()->sections);
    }

    public function test_publish_flag_publishes_created_days(): void
    {
        $template = $this->weeklyTemplate();

        $this->service->apply($template, '2026-08-17', '2026-08-17', publish: true);

        $this->assertNotNull(MenuDay::query()->firstOrFail()->published_at);
    }

    public function test_fortnightly_template_alternates_weeks(): void
    {
        $template = MenuTemplate::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Двотижневий',
            'cycle_length' => 14,
        ]);

        $this->service->ensureDays($template);

        // Понеділок першого тижня — котлета, понеділок другого — суп.
        $this->sectionWithDish(
            $template->days()->where('day_index', 1)->firstOrFail(),
            'Тиждень 1',
            $this->cutlet,
        );

        $this->sectionWithDish(
            $template->days()->where('day_index', 8)->firstOrFail(),
            'Тиждень 2',
            $this->soup,
        );

        $this->service->apply($template, '2026-08-17', '2026-08-31');

        $week1 = MenuDay::query()->whereDate('date', '2026-08-17')->firstOrFail();
        $week2 = MenuDay::query()->whereDate('date', '2026-08-24')->firstOrFail();
        $week3 = MenuDay::query()->whereDate('date', '2026-08-31')->firstOrFail();

        $this->assertSame('Тиждень 1', $week1->sections->first()->title);
        $this->assertSame('Тиждень 2', $week2->sections->first()->title);
        // Цикл повторюється: третій тиждень знову перший.
        $this->assertSame('Тиждень 1', $week3->sections->first()->title);
    }

    public function test_week_is_copied_to_the_next_one(): void
    {
        $menuDay = MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => '2026-08-18',   // вівторок
            'is_working_day' => true,
            'published_at' => now(),
        ]);

        $section = $menuDay->sections()->create([
            'type' => MenuSectionType::Choice,
            'title' => 'Перша страва',
            'sort' => 0,
        ]);

        $section->sectionDishes()->create(['dish_id' => $this->soup->id, 'sort' => 0]);

        $result = $this->service->copyWeek($this->supplier, '2026-08-17', '2026-08-24');

        $this->assertSame(1, $result->created);

        $copy = MenuDay::query()->whereDate('date', '2026-08-25')->firstOrFail();

        $this->assertSame('Перша страва', $copy->sections->first()->title);
        $this->assertSame($this->soup->id, $copy->sections->first()->sectionDishes->first()->dish_id);
        // Копія лишається чернеткою, поки її не опублікують окремо.
        $this->assertNull($copy->published_at);
    }

    private function weeklyTemplate(): MenuTemplate
    {
        $template = MenuTemplate::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Основний тиждень',
            'cycle_length' => 7,
        ]);

        $this->service->ensureDays($template);

        foreach (range(1, 5) as $dayIndex) {
            $day = $template->days()->where('day_index', $dayIndex)->firstOrFail();

            $section = $day->sections()->create([
                'type' => MenuSectionType::Complex,
                'title' => 'Комплекс №1',
                'sort' => 0,
            ]);

            $section->sectionDishes()->create(['dish_id' => $this->cutlet->id, 'sort' => 0]);
            $section->sectionDishes()->create(['dish_id' => $this->soup->id, 'sort' => 1]);
        }

        return $template->fresh();
    }

    private function sectionWithDish(MenuTemplateDay $templateDay, string $title, Dish $dish): void
    {
        $section = $templateDay->sections()->create([
            'type' => MenuSectionType::Complex,
            'title' => $title,
            'sort' => 0,
        ]);

        $section->sectionDishes()->create(['dish_id' => $dish->id, 'sort' => 0]);
    }
}
