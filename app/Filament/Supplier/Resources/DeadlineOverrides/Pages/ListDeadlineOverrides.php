<?php

namespace App\Filament\Supplier\Resources\DeadlineOverrides\Pages;

use App\Filament\Supplier\Resources\DeadlineOverrides\DeadlineOverrideResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDeadlineOverrides extends ListRecords
{
    protected static string $resource = DeadlineOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
