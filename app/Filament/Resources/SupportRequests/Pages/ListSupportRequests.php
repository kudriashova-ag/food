<?php

namespace App\Filament\Resources\SupportRequests\Pages;

use App\Filament\Resources\SupportRequests\SupportRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListSupportRequests extends ListRecords
{
    protected static string $resource = SupportRequestResource::class;
}
