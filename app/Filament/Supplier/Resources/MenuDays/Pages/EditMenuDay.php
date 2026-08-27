<?php

namespace App\Filament\Supplier\Resources\MenuDays\Pages;

use App\Filament\Supplier\Concerns\HandlesPublication;
use App\Filament\Supplier\Resources\MenuDays\MenuDayResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMenuDay extends EditRecord
{
    use HandlesPublication;

    protected static string $resource = MenuDayResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return $this->publicationTimestampToToggle($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->publicationToggleToTimestamp($data);
    }
}
