<?php

namespace App\Filament\Supplier\Resources\MenuTemplates\Pages;

use App\Filament\Supplier\Resources\MenuTemplates\MenuTemplateResource;
use App\Models\MenuTemplate;
use App\Services\Menu\MenuTemplateService;
use Filament\Resources\Pages\CreateRecord;

class CreateMenuTemplate extends CreateRecord
{
    protected static string $resource = MenuTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['supplier_id'] = MenuTemplateResource::currentSupplierId();

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var MenuTemplate $template */
        $template = $this->record;

        // Дні створюються одразу — постачальнику лишається їх заповнити.
        app(MenuTemplateService::class)->ensureDays($template);
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
