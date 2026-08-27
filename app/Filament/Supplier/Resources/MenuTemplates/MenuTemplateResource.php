<?php

namespace App\Filament\Supplier\Resources\MenuTemplates;

use App\Filament\Supplier\Concerns\ScopedToSupplier;
use App\Filament\Supplier\Resources\MenuTemplates\Pages\CreateMenuTemplate;
use App\Filament\Supplier\Resources\MenuTemplates\Pages\EditMenuTemplate;
use App\Filament\Supplier\Resources\MenuTemplates\Pages\ListMenuTemplates;
use App\Filament\Supplier\Resources\MenuTemplates\RelationManagers\DaysRelationManager;
use App\Filament\Supplier\Resources\MenuTemplates\Schemas\MenuTemplateForm;
use App\Filament\Supplier\Resources\MenuTemplates\Tables\MenuTemplatesTable;
use App\Models\MenuTemplate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MenuTemplateResource extends Resource
{
    use ScopedToSupplier;

    protected static ?string $model = MenuTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Шаблони меню';

    protected static ?string $modelLabel = 'шаблон';

    protected static ?string $pluralModelLabel = 'шаблони меню';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return MenuTemplateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenuTemplatesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DaysRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuTemplates::route('/'),
            'create' => CreateMenuTemplate::route('/create'),
            'edit' => EditMenuTemplate::route('/{record}/edit'),
        ];
    }
}
