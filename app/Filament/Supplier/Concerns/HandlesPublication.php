<?php

namespace App\Filament\Supplier\Concerns;

/**
 * У БД публікація — це timestamp `published_at`, у формі — звичайний перемикач.
 * Тут конвертація в обидва боки.
 */
trait HandlesPublication
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function publicationToggleToTimestamp(array $data): array
    {
        $isPublished = (bool) ($data['is_published'] ?? false);

        unset($data['is_published']);

        $data['published_at'] = $isPublished
            ? ($this->record?->published_at ?? now())
            : null;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function publicationTimestampToToggle(array $data): array
    {
        $data['is_published'] = ! empty($data['published_at']);

        return $data;
    }
}
