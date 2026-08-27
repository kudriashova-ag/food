<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuTemplate extends Model
{
    protected $fillable = [
        'supplier_id',
        'name',
        'cycle_length',
    ];

    protected function casts(): array
    {
        return [
            'cycle_length' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(MenuTemplateDay::class)->orderBy('day_index');
    }
}
