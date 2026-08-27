<?php

namespace App\Filament\Supplier\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Ізоляція даних постачальника на рівні запитів (ТЗ, п. 15.2):
 * у кабінет потрапляють лише власні записи, незалежно від того,
 * який id підставили в адресний рядок.
 */
trait ScopedToSupplier
{
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('supplier_id', static::currentSupplierId());
    }

    public static function currentSupplierId(): ?int
    {
        return auth()->user()?->supplier_id;
    }
}
