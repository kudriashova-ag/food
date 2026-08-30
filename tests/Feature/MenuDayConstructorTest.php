<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Filament\Supplier\Resources\MenuDays\Pages\CreateMenuDay;
use App\Filament\Supplier\Resources\MenuDays\Pages\ListMenuDays;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MenuDayConstructorTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Dish $cutlet;

    private Dish $puree;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('supplier');

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $user = User::create([
            'name' => 'Смачно',
            'email' => 'smachno@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $this->supplier->id,
        ]);

        $this->actingAs($user);

        $this->cutlet = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Куряча котлета',
            'price' => 60,
        ]);

        $this->puree = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Картопляне пюре',
            'price' => 35,
        ]);
    }

    public function test_menu_day_is_saved_with_sections_and_dishes(): void
    {
        Livewire::test(CreateMenuDay::class)
            ->fillForm([
                'date' => '2026-08-17',
                'is_working_day' => true,
                'is_published' => true,
                'sections' => [
                    [
                        'type' => MenuSectionType::Complex->value,
                        'title' => 'Комплекс №1',
                        'price' => 95,
                        'sectionDishes' => [
                            ['dish_id' => $this->cutlet->id],
                            ['dish_id' => $this->puree->id],
                        ],
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $menuDay = MenuDay::query()->firstOrFail();

        $this->assertSame($this->supplier->id, $menuDay->supplier_id);
        $this->assertNotNull($menuDay->published_at);
        $this->assertCount(1, $menuDay->sections);

        $section = $menuDay->sections->first();

        $this->assertSame(MenuSectionType::Complex, $section->type);
        $this->assertCount(2, $section->sectionDishes);
        $this->assertSame(
            [$this->cutlet->id, $this->puree->id],
            $section->sectionDishes->pluck('dish_id')->all(),
        );
    }

    public function test_blank_section_title_gets_a_default_by_type(): void
    {
        Livewire::test(CreateMenuDay::class)
            ->fillForm([
                'date' => '2026-08-17',
                'is_working_day' => true,
                'sections' => [
                    [
                        'type' => MenuSectionType::Complex->value,
                        'title' => '',
                        'price' => 95,
                        'sectionDishes' => [['dish_id' => $this->cutlet->id]],
                    ],
                    [
                        'type' => MenuSectionType::Choice->value,
                        'title' => '',
                        'sectionDishes' => [['dish_id' => $this->puree->id]],
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $sections = MenuDay::query()->firstOrFail()->sections;

        $this->assertSame('Комплекс №1', $sections->firstWhere('type', MenuSectionType::Complex)->title);
        $this->assertSame('Перша страва', $sections->firstWhere('type', MenuSectionType::Choice)->title);
    }

    public function test_draft_menu_day_has_no_publication_timestamp(): void
    {
        Livewire::test(CreateMenuDay::class)
            ->fillForm([
                'date' => '2026-08-18',
                'is_working_day' => true,
                'is_published' => false,
                'sections' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertNull(MenuDay::query()->firstOrFail()->published_at);
    }

    public function test_more_than_three_complexes_are_rejected(): void
    {
        $sections = [];

        foreach (range(1, 4) as $number) {
            $sections[] = [
                'type' => MenuSectionType::Complex->value,
                'title' => "Комплекс №{$number}",
                'price' => 60,
                'sectionDishes' => [['dish_id' => $this->cutlet->id]],
            ];
        }

        Livewire::test(CreateMenuDay::class)
            ->fillForm([
                'date' => '2026-08-19',
                'is_working_day' => true,
                'sections' => $sections,
            ])
            ->call('create')
            ->assertHasFormErrors(['sections']);

        $this->assertSame(0, MenuDay::query()->count());
    }

    public function test_second_menu_for_the_same_date_is_rejected(): void
    {
        MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => '2026-08-20',
        ]);

        Livewire::test(CreateMenuDay::class)
            ->fillForm([
                'date' => '2026-08-20',
                'is_working_day' => true,
                'sections' => [],
            ])
            ->call('create')
            ->assertHasFormErrors(['date']);
    }

    public function test_supplier_sees_only_its_own_menu_days(): void
    {
        $own = MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => today()->addDay(),
        ]);

        $other = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        $foreign = MenuDay::create([
            'supplier_id' => $other->id,
            'date' => today()->addDay(),
        ]);

        Livewire::test(ListMenuDays::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }
}
