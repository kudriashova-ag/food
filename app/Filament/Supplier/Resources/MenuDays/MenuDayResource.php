<?php

namespace App\Filament\Supplier\Resources\MenuDays;

use App\Filament\Supplier\Concerns\ScopedToSupplier;
use App\Filament\Supplier\Resources\MenuDays\Pages\CreateMenuDay;
use App\Filament\Supplier\Resources\MenuDays\Pages\EditMenuDay;
use App\Filament\Supplier\Resources\MenuDays\Pages\ListMenuDays;
use App\Filament\Supplier\Resources\MenuDays\Schemas\MenuDayForm;
use App\Filament\Supplier\Resources\MenuDays\Tables\MenuDaysTable;
use App\Models\MenuDay;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MenuDayResource extends Resource
{
    use ScopedToSupplier;

    protected static ?string $model = MenuDay::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Меню по днях';

    protected static ?string $modelLabel = 'меню дня';

    protected static ?string $pluralModelLabel = 'меню по днях';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return MenuDayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenuDaysTable::configure($table);
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
            'index' => ListMenuDays::route('/'),
            'create' => CreateMenuDay::route('/create'),
            'edit' => EditMenuDay::route('/{record}/edit'),
        ];
    }
}
