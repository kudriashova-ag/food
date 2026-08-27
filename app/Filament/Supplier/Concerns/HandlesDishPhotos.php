<?php

namespace App\Filament\Supplier\Concerns;

use App\Models\Dish;
use Illuminate\Support\Facades\Storage;

/**
 * Фото зберігаються окремими рядками в dish_photos, а у формі це одне поле
 * з кількома файлами. Основним вважається перше — так постачальнику не треба
 * окремо ставити галочку «основне».
 */
trait HandlesDishPhotos
{
    /** @var array<int, string> */
    protected array $photoPaths = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function pullPhotosOut(array $data): array
    {
        $this->photoPaths = array_values($data['photos'] ?? []);

        unset($data['photos']);

        return $data;
    }

    protected function syncPhotos(Dish $dish): void
    {
        $existing = $dish->photos()->pluck('path')->all();

        $dish->photos()->delete();

        foreach ($this->photoPaths as $index => $path) {
            $dish->photos()->create([
                'path' => $path,
                'is_primary' => $index === 0,
                'sort' => $index,
            ]);
        }

        // Прибираємо з диска файли, які більше не використовуються.
        $orphans = array_diff($existing, $this->photoPaths);

        if ($orphans !== []) {
            Storage::disk('public')->delete($orphans);
        }
    }
}
