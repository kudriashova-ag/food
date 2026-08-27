<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Supplier\Resources\Dishes\Pages\CreateDish;
use App\Filament\Supplier\Resources\Dishes\Pages\ListDishes;
use App\Models\Dish;
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
}
