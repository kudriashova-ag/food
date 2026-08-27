<?php

namespace App\Models;

use App\Models\Concerns\HasGeneratedSlug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Allergen extends Model
{
    use HasGeneratedSlug;

    protected $fillable = [
        'name',
        'slug',
    ];

    protected static function booted(): void
    {
        // Slug технічний і в інтерфейсі не потрібен — заповнюємо самі.
        static::saving(function (Allergen $allergen): void {
            if (blank($allergen->slug) || $allergen->isDirty('name')) {
                $allergen->slug = static::generateSlug($allergen->name, $allergen->id);
            }
        });
    }

    public function dishes(): BelongsToMany
    {
        return $this->belongsToMany(Dish::class);
    }
}
