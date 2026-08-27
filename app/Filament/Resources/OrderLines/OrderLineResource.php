<?php

namespace App\Filament\Resources\OrderLines;

use App\Filament\Resources\OrderLines\Pages\ListOrderLines;
use App\Filament\Resources\OrderLines\Tables\OrderLinesTable;
use App\Models\OrderLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class OrderLineResource extends Resource
{
    protected static ?string $model = OrderLine::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Замовлення';

    protected static ?string $modelLabel = 'позиція замовлення';

    protected static ?string $pluralModelLabel = 'замовлення';

    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return OrderLinesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrderLines::route('/'),
        ];
    }
}
