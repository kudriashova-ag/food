<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

trait HasGeneratedSlug
{
    /**
     * Slug з української назви: «Глютен» → «hliuten».
     * Якщо транслітерація дала порожній рядок або такий slug уже є — додаємо суфікс.
     */
    public static function generateSlug(string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug($name) ?: Str::slug(Str::ascii($name)) ?: 'item';
        $slug = $base;
        $suffix = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
