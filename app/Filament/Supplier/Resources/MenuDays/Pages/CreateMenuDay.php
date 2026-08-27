<?php

namespace App\Filament\Supplier\Resources\MenuDays\Pages;

use App\Filament\Supplier\Concerns\HandlesPublication;
use App\Filament\Supplier\Resources\MenuDays\MenuDayResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMenuDay extends CreateRecord
{
    use HandlesPublication;

    protected static string $resource = MenuDayResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['supplier_id'] = MenuDayResource::currentSupplierId();

        return $this->publicationToggleToTimestamp($data);
    }
}
