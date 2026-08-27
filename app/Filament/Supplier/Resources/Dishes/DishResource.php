<?php

namespace App\Filament\Supplier\Resources\Dishes;

use App\Filament\Supplier\Concerns\ScopedToSupplier;
use App\Filament\Supplier\Resources\Dishes\Pages\CreateDish;
use App\Filament\Supplier\Resources\Dishes\Pages\EditDish;
use App\Filament\Supplier\Resources\Dishes\Pages\ListDishes;
use App\Filament\Supplier\Resources\Dishes\Schemas\DishForm;
use App\Filament\Supplier\Resources\Dishes\Tables\DishesTable;
use App\Models\Dish;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DishResource extends Resource
{
    use ScopedToSupplier;

    protected static ?string $model = Dish::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Бібліотека страв';

    protected static ?string $modelLabel = 'страва';

    protected static ?string $pluralModelLabel = 'страви';

    protected static ?int $navigationSort = 1;

    public static function getRecordTitleAttribute(): ?string
    {
        return 'name';
    }

    public static function form(Schema $schema): Schema
    {
        return DishForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DishesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDishes::route('/'),
            'create' => CreateDish::route('/create'),
            'edit' => EditDish::route('/{record}/edit'),
        ];
    }
}
