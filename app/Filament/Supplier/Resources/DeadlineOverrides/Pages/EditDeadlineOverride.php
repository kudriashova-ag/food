<?php

namespace App\Filament\Supplier\Resources\DeadlineOverrides\Pages;

use App\Filament\Supplier\Resources\DeadlineOverrides\DeadlineOverrideResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditDeadlineOverride extends EditRecord
{
    protected static string $resource = DeadlineOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
