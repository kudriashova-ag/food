<?php

namespace App\Filament\Supplier\Resources\Orders;

use App\Enums\OrderLineStatus;
use App\Filament\Supplier\Resources\Orders\Pages\ListOrders;
use App\Filament\Supplier\Resources\Orders\Pages\ViewOrder;
use App\Filament\Supplier\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Постачальник бачить замовлення в межах власних позицій: чужі страви
 * з того самого чека до нього не потрапляють (ТЗ, п. 2.2).
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Замовлення';

    protected static ?string $modelLabel = 'замовлення';

    protected static ?string $pluralModelLabel = 'замовлення';

    protected static ?int $navigationSort = 7;

    public static function currentSupplierId(): ?int
    {
        return auth()->user()?->supplier_id;
    }

    public static function getEloquentQuery(): Builder
    {
        $supplierId = static::currentSupplierId();

        $ownLines = fn (Builder $query) => $query
            ->where('supplier_id', $supplierId)
            ->where('status', OrderLineStatus::Active);

        return parent::getEloquentQuery()
            ->whereHas('lines', fn (Builder $query) => $query->where('supplier_id', $supplierId))
            ->with([
                'student.schoolClass',
                'schoolClass',
                'lines' => fn ($query) => $query->where('supplier_id', $supplierId)->orderBy('service_date'),
            ])
            // Сума й кількість — тільки за власними активними позиціями.
            ->withSum(['lines as supplier_total' => $ownLines], DB::raw('quantity * unit_price'))
            ->withSum(['lines as supplier_positions' => $ownLines], 'quantity');
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }
}
