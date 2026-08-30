<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Filament\Supplier\Resources\Dishes\Pages\CreateDish;
use App\Filament\Supplier\Resources\Dishes\Pages\ListDishes;
use App\Models\Dish;
use App\Models\MenuDay;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class DishLibraryTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    private Supplier $otherSupplier;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('supplier');

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $this->otherSupplier = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        $this->user = User::create([
            'name' => 'Смачно',
            'email' => 'smachno@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $this->supplier->id,
        ]);

        $this->actingAs($this->user);
    }

    public function test_supplier_sees_only_its_own_dishes(): void
    {
        $own = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Куряча котлета',
            'price' => 60,
        ]);

        $foreign = Dish::create([
            'supplier_id' => $this->otherSupplier->id,
            'name' => 'Сирники',
            'price' => 45,
        ]);

        Livewire::test(ListDishes::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);
    }

    public function test_created_dish_belongs_to_the_current_supplier(): void
    {
        Storage::fake('public');

        Livewire::test(CreateDish::class)
            ->fillForm([
                'name' => 'Борщ',
                'price' => 40,
                'portion' => '300 мл',
                'photos' => [UploadedFile::fake()->image('borshch.jpg')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $dish = Dish::query()->where('name', 'Борщ')->firstOrFail();

        $this->assertSame($this->supplier->id, $dish->supplier_id);
        $this->assertFalse($dish->is_archived);
    }

    public function test_first_photo_becomes_the_primary_one(): void
    {
        Storage::fake('public');

        Livewire::test(CreateDish::class)
            ->fillForm([
                'name' => 'Паста з куркою',
                'price' => 95,
                'photos' => [
                    UploadedFile::fake()->image('first.jpg'),
                    UploadedFile::fake()->image('second.jpg'),
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $dish = Dish::query()->where('name', 'Паста з куркою')->firstOrFail();

        $this->assertCount(2, $dish->photos);
        $this->assertTrue($dish->photos()->orderBy('sort')->first()->is_primary);
        $this->assertSame(1, $dish->photos()->where('is_primary', true)->count());
    }

    public function test_dish_requires_a_photo(): void
    {
        Storage::fake('public');

        Livewire::test(CreateDish::class)
            ->fillForm([
                'name' => 'Без фото',
                'price' => 10,
            ])
            ->call('create')
            ->assertHasFormErrors(['photos']);
    }

    public function test_unused_dish_is_deleted_by_the_bulk_action(): void
    {
        $dish = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Вода 0,5 л',
            'price' => 15,
        ]);

        Livewire::test(ListDishes::class)
            ->callTableBulkAction('deleteOrArchive', [$dish->id]);

        $this->assertNull(Dish::find($dish->id));
    }

    /**
     * FK на dish_id у menu_section_dishes є restrictOnDelete — фізичне
     * видалення такої страви MySQL відхилить. Замість помилки "не видаляється"
     * страва має архівуватися.
     */
    public function test_dish_used_in_a_menu_is_archived_instead_of_deleted(): void
    {
        $dish = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Куряча котлета',
            'price' => 60,
        ]);

        $menuDay = MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => '2026-09-01',
            'is_working_day' => true,
        ]);

        $section = $menuDay->sections()->create([
            'type' => MenuSectionType::Complex,
            'title' => 'Комплекс №1',
            'price' => 60,
            'sort' => 0,
        ]);

        $section->sectionDishes()->create(['dish_id' => $dish->id, 'sort' => 0]);

        Livewire::test(ListDishes::class)
            ->callTableBulkAction('deleteOrArchive', [$dish->id]);

        $dish->refresh();

        $this->assertNotNull($dish);
        $this->assertTrue($dish->is_archived);
    }

    public function test_is_in_use_reports_all_the_places_a_dish_can_be_referenced(): void
    {
        $free = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Вільна страва',
            'price' => 20,
        ]);

        $this->assertFalse($free->isInUse());

        $used = Dish::create([
            'supplier_id' => $this->supplier->id,
            'name' => 'Зайнята страва',
            'price' => 30,
        ]);

        $menuDay = MenuDay::create([
            'supplier_id' => $this->supplier->id,
            'date' => '2026-09-02',
            'is_working_day' => true,
        ]);

        $section = $menuDay->sections()->create([
            'type' => MenuSectionType::Extra,
            'title' => 'Додатково',
            'sort' => 0,
        ]);

        $section->sectionDishes()->create(['dish_id' => $used->id, 'sort' => 0]);

        $this->assertTrue($used->isInUse());
    }
}
