<?php

namespace App\Models;

use App\Enums\MenuSectionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuSection extends Model
{
    protected $fillable = [
        'menu_day_id',
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

    public function menuDay(): BelongsTo
    {
        return $this->belongsTo(MenuDay::class);
    }

    public function sectionDishes(): HasMany
    {
        return $this->hasMany(MenuSectionDish::class)->orderBy('sort');
    }

    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(Dish::class, 'menu_section_dishes')
            ->withPivot('sort')
            ->orderBy('menu_section_dishes.sort');
    }
}
