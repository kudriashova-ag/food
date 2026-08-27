<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;

/**
 * Журнал змін (ТЗ, п. 13): записи не редагуються й не видаляються.
 *
 * Модель, що належить постачальнику, має оголосити метод activitySupplierId() —
 * тоді запис отримає властивість supplier_id, і постачальник побачить
 * у журналі лише свої дані.
 */
trait RecordsActivity
{
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->activityAttributes())
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn (string $eventName): string => match ($eventName) {
                'created' => static::activityLabel().': створено',
                'updated' => static::activityLabel().': змінено',
                'deleted' => static::activityLabel().': видалено',
                default => static::activityLabel().": {$eventName}",
            });
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if (! method_exists($this, 'activitySupplierId')) {
            return;
        }

        $properties = $activity->properties->toArray();
        $properties['supplier_id'] = $this->activitySupplierId();

        $activity->properties = collect($properties);
    }

    /** @return array<int, string> */
    abstract protected function activityAttributes(): array;

    abstract protected static function activityLabel(): string;
}
