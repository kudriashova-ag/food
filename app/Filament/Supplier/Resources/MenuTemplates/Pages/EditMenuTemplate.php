<?php

namespace App\Filament\Supplier\Resources\MenuTemplates\Pages;

use App\Filament\Supplier\Resources\MenuTemplates\MenuTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMenuTemplate extends EditRecord
{
    protected static string $resource = MenuTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
