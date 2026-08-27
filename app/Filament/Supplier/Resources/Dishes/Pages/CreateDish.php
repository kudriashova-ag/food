<?php

namespace App\Filament\Supplier\Resources\Dishes\Pages;

use App\Filament\Supplier\Concerns\HandlesDishPhotos;
use App\Filament\Supplier\Resources\Dishes\DishResource;
use App\Models\Dish;
use Filament\Resources\Pages\CreateRecord;

class CreateDish extends CreateRecord
{
    use HandlesDishPhotos;

    protected static string $resource = DishResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['supplier_id'] = DishResource::currentSupplierId();

        return $this->pullPhotosOut($data);
    }

    protected function afterCreate(): void
    {
        /** @var Dish $dish */
        $dish = $this->record;

        $this->syncPhotos($dish);
    }
}
