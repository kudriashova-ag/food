<?php

namespace App\Filament\Supplier\Resources\Dishes\Pages;

use App\Filament\Supplier\Concerns\HandlesDishPhotos;
use App\Filament\Supplier\Resources\Dishes\DishResource;
use App\Models\Dish;
use Filament\Resources\Pages\EditRecord;

class EditDish extends EditRecord
{
    use HandlesDishPhotos;

    protected static string $resource = DishResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Dish $dish */
        $dish = $this->record;

        $data['photos'] = $dish->photos()->orderBy('sort')->pluck('path')->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->pullPhotosOut($data);
    }

    protected function afterSave(): void
    {
        /** @var Dish $dish */
        $dish = $this->record;

        $this->syncPhotos($dish);
    }
}
