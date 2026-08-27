<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuTemplateSectionDish extends Model
{
    protected $fillable = [
        'menu_template_section_id',
        'dish_id',
        'sort',
    ];

    public function section(): BelongsTo
    {
        return $this->belongsTo(MenuTemplateSection::class, 'menu_template_section_id');
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }
}
