<?php

namespace App\Models;

use App\Enums\MenuSectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuTemplateSection extends Model
{
    protected $fillable = [
        'menu_template_day_id',
        'type',
        'title',
        'price',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'type' => MenuSectionType::class,
            'price' => 'decimal:2',
        ];
    }

    public function isComplex(): bool
    {
        return $this->type === MenuSectionType::Complex;
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(MenuTemplateDay::class, 'menu_template_day_id');
    }

    public function sectionDishes(): HasMany
    {
        return $this->hasMany(MenuTemplateSectionDish::class, 'menu_template_section_id')->orderBy('sort');
    }

    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(Dish::class, 'menu_template_section_dishes')
            ->withPivot('sort')
            ->orderBy('menu_template_section_dishes.sort');
    }
}
