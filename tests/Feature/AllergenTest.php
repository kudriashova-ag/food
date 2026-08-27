<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Resources\Allergens\Pages\CreateAllergen;
use App\Filament\Resources\Allergens\Pages\ListAllergens;
use App\Filament\Supplier\Resources\Dishes\Pages\CreateDish;
use App\Models\Allergen;
use App\Models\Dish;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AllergenTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_creates_an_allergen_with_a_generated_slug(): void
    {
        $this->asAdmin();

        Livewire::test(CreateAllergen::class)
            ->fillForm(['name' => 'Глютен'])
            ->call('create')
            ->assertHasNoFormErrors();

        $allergen = Allergen::query()->firstOrFail();

        $this->assertSame('Глютен', $allergen->name);
        $this->assertNotEmpty($allergen->slug);
    }

    public function test_slug_stays_unique_for_similar_names(): void
    {
        $first = Allergen::create(['name' => 'Горіхи']);
        $second = Allergen::create(['name' => 'Горіхи ']);

        $this->assertNotSame($first->slug, $second->slug);
    }

    public function test_duplicate_name_is_rejected(): void
    {
        $this->asAdmin();

        Allergen::create(['name' => 'Лактоза']);

        Livewire::test(CreateAllergen::class)
            ->fillForm(['name' => 'Лактоза'])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    public function test_allergen_list_shows_how_many_dishes_use_it(): void
    {
        $this->asAdmin();

        $allergen = Allergen::create(['name' => 'Риба']);
        $supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $dish = Dish::create(['supplier_id' => $supplier->id, 'name' => 'Оселедець', 'price' => 40]);
        $dish->allergens()->attach($allergen);

        Livewire::test(ListAllergens::class)
            ->assertCanSeeTableRecords([$allergen])
            ->assertSee('Риба');
    }

    public function test_deleting_an_allergen_keeps_the_dish(): void
    {
        $allergen = Allergen::create(['name' => 'Соя']);
        $supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $dish = Dish::create(['supplier_id' => $supplier->id, 'name' => 'Котлета', 'price' => 60]);
        $dish->allergens()->attach($allergen);

        $allergen->delete();

        $this->assertModelExists($dish);
        $this->assertCount(0, $dish->fresh()->allergens);
    }

    public function test_supplier_adds_a_missing_allergen_from_the_dish_form(): void
    {
        Storage::fake('public');
        Filament::setCurrentPanel('supplier');

        $supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $this->actingAs(User::create([
            'name' => 'Смачно',
            'email' => 'smachno@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $supplier->id,
        ]));

        $allergen = Allergen::create(['name' => 'Мед']);

        Livewire::test(CreateDish::class)
            ->fillForm([
                'name' => 'Медовик',
                'price' => 35,
                'photos' => [UploadedFile::fake()->image('cake.jpg')],
                'allergens' => [$allergen->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $dish = Dish::query()->where('name', 'Медовик')->firstOrFail();

        $this->assertCount(1, $dish->allergens);
        $this->assertSame('Мед', $dish->allergens->first()->name);
    }

    private function asAdmin(): void
    {
        Filament::setCurrentPanel('admin');

        $this->actingAs(User::create([
            'name' => 'Адміністратор',
            'email' => 'admin@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]));
    }
}
