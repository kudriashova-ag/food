<?php

namespace App\Filament\Supplier\Resources\DeadlineOverrides\Pages;

use App\Filament\Supplier\Resources\DeadlineOverrides\DeadlineOverrideResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDeadlineOverride extends CreateRecord
{
    protected static string $resource = DeadlineOverrideResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['supplier_id'] = DeadlineOverrideResource::currentSupplierId();
        $data['created_by'] = auth()->id();

        return $data;
    }
}
