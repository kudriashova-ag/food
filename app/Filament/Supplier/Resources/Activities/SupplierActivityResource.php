<?php

namespace App\Filament\Supplier\Resources\Activities;

use App\Filament\Resources\Activities\Tables\ActivitiesTable;
use App\Filament\Supplier\Resources\Activities\Pages\ListSupplierActivities;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/** Постачальник бачить журнал у межах своїх даних (ТЗ, п. 13). */
class SupplierActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $slug = 'activities';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $navigationLabel = 'Журнал змін';

    protected static ?string $modelLabel = 'запис журналу';

    protected static ?string $pluralModelLabel = 'журнал змін';

    protected static ?int $navigationSort = 8;

    public static function getEloquentQuery(): Builder
    {
        // Записи позначаються supplier_id під час створення — по ньому й фільтруємо.
        return parent::getEloquentQuery()
            ->where('properties->supplier_id', auth()->user()?->supplier_id);
    }

    public static function table(Table $table): Table
    {
        return ActivitiesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierActivities::route('/'),
        ];
    }
}
