<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuSectionDish extends Model
{
    protected $fillable = [
        'menu_section_id',
        'dish_id',
        'sort',
    ];

    public function menuSection(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }
}
