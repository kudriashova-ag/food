<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Filament\Supplier\Resources\MenuDays\Pages\ListMenuDays;
use App\Filament\Supplier\Resources\MenuTemplates\Pages\CreateMenuTemplate;
use App\Filament\Supplier\Resources\MenuTemplates\Pages\ListMenuTemplates;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\MenuTemplate;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Menu\MenuTemplateService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MenuTemplatePanelTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Dish $dish;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('supplier');

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $this->actingAs(User::create([
            'name' => 'Смачно',
            'email' => 'smachno@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $this->supplier->id,
        ]));

        $this->dish = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Куряча котлета',
            'price' => 60,
        ]);
    }

    public function test_new_template_gets_its_days_created(): void
    {
        Livewire::test(CreateMenuTemplate::class)
            ->fillForm([
                'name' => 'Основний тиждень',
                'cycle_length' => 7,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $template = MenuTemplate::query()->firstOrFail();

        $this->assertSame($this->supplier->id, $template->supplier_id);
        $this->assertCount(7, $template->days);
        // Субота й неділя за замовчуванням неробочі.
        $this->assertFalse($template->days->firstWhere('day_index', 6)->is_working_day);
        $this->assertTrue($template->days->firstWhere('day_index', 1)->is_working_day);
    }

    public function test_apply_action_fills_the_date_range(): void
    {
        $template = $this->templateWithMonday();

        Livewire::test(ListMenuTemplates::class)
            ->callTableAction('apply', $template, [
                'from' => '2026-08-17',
                'to' => '2026-08-23',
                'overwrite' => false,
                'publish' => true,
            ])
            ->assertHasNoActionErrors();

        $monday = MenuDay::query()->whereDate('date', '2026-08-17')->firstOrFail();

        $this->assertNotNull($monday->published_at);
        $this->assertSame('Комплекс №1', $monday->sections->first()->title);
    }

    public function test_copy_week_action_duplicates_the_week(): void
    {
        $menuDay = MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => '2026-08-17',
            'is_working_day' => true,
        ]);

        $section = $menuDay->sections()->create([
            'type' => MenuSectionType::Extra,
            'title' => 'Додатково',
            'sort' => 0,
        ]);

        $section->sectionDishes()->create(['dish_id' => $this->dish->id, 'sort' => 0]);

        Livewire::test(ListMenuDays::class)
            ->callAction('copyWeek', data: [
                'source' => '2026-08-17',
                'target' => '2026-08-24',
                'overwrite' => false,
                'publish' => false,
            ])
            ->assertHasNoActionErrors();

        $copy = MenuDay::query()->whereDate('date', '2026-08-24')->firstOrFail();

        $this->assertSame('Додатково', $copy->sections->first()->title);
    }

    private function templateWithMonday(): MenuTemplate
    {
        $template = MenuTemplate::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Основний тиждень',
            'cycle_length' => 7,
        ]);

        app(MenuTemplateService::class)->ensureDays($template);

        $section = $template->days()->where('day_index', 1)->firstOrFail()->sections()->create([
            'type' => MenuSectionType::Complex,
            'title' => 'Комплекс №1',
            'sort' => 0,
        ]);

        $section->sectionDishes()->create(['dish_id' => $this->dish->id, 'sort' => 0]);

        return $template->fresh();
    }
}
