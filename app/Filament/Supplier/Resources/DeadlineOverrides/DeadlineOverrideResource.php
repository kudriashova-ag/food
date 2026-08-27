<?php

namespace App\Filament\Supplier\Resources\DeadlineOverrides;

use App\Filament\Supplier\Concerns\ScopedToSupplier;
use App\Filament\Supplier\Resources\DeadlineOverrides\Pages\CreateDeadlineOverride;
use App\Filament\Supplier\Resources\DeadlineOverrides\Pages\EditDeadlineOverride;
use App\Filament\Supplier\Resources\DeadlineOverrides\Pages\ListDeadlineOverrides;
use App\Filament\Supplier\Resources\DeadlineOverrides\Schemas\DeadlineOverrideForm;
use App\Filament\Supplier\Resources\DeadlineOverrides\Tables\DeadlineOverridesTable;
use App\Models\DeadlineOverride;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class DeadlineOverrideResource extends Resource
{
    use ScopedToSupplier;

    protected static ?string $model = DeadlineOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Винятки з дедлайнів';

    protected static ?string $modelLabel = 'виняток';

    protected static ?string $pluralModelLabel = 'винятки з дедлайнів';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return DeadlineOverrideForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DeadlineOverridesTable::configure($table);
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
            'index' => ListDeadlineOverrides::route('/'),
            'create' => CreateDeadlineOverride::route('/create'),
            'edit' => EditDeadlineOverride::route('/{record}/edit'),
        ];
    }
}
