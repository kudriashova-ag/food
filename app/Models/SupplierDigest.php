<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Факт відправленого зведення на дату.
 *
 * Потрібен для двох речей: не слати той самий дайджест двічі
 * і знати, з якого моменту скасування вже варті окремого сигналу кухні.
 */
class SupplierDigest extends Model
{
    protected $fillable = [
        'supplier_id',
        'service_date',
        'is_final',
        'positions',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'is_final' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
