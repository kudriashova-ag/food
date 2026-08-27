<?php

namespace App\Filament\Supplier\Resources\Orders\Pages;

use App\Filament\Supplier\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;
}
