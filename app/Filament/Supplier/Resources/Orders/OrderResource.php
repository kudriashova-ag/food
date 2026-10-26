<?php

namespace App\Filament\Supplier\Resources\Orders;

use App\Filament\Supplier\Resources\Orders\Pages\ListOrders;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;

/**
 * Замовлення постачальника — таблиця «хто що замовив» за період.
 * Постачальник бачить лише власні позиції: чужі страви з того самого
 * чека до нього не потрапляють (ТЗ, п. 2.2).
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Замовлення';

    protected static ?string $modelLabel = 'замовлення';

    protected static ?string $pluralModelLabel = 'замовлення';

    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }
}
