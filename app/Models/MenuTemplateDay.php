<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuTemplateDay extends Model
{
    protected $fillable = [
        'menu_template_id',
        'day_index',
        'is_working_day',
    ];

    protected function casts(): array
    {
        return [
            'day_index' => 'integer',
            'is_working_day' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(MenuTemplate::class, 'menu_template_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(MenuTemplateSection::class)->orderBy('sort');
    }
}
