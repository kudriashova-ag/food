<?php

namespace App\Filament\Resources\OrderLines\Pages;

use App\Filament\Resources\OrderLines\OrderLineResource;
use Filament\Resources\Pages\ListRecords;

class ListOrderLines extends ListRecords
{
    protected static string $resource = OrderLineResource::class;
}
