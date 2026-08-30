<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Traits\LogsActivity;

class Dish extends Model
{
    use LogsActivity, RecordsActivity;

    protected $fillable = [
        'supplier_id',
        'name',
        'description',
        'composition',
        'portion',
        'price',
        'is_archived',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_archived' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(DishPhoto::class)->orderBy('sort');
    }

    public function primaryPhoto(): HasOne
    {
        return $this->hasOne(DishPhoto::class)->where('is_primary', true);
    }

    public function allergens(): BelongsToMany
    {
        return $this->belongsToMany(Allergen::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_archived', false);
    }

    /**
     * Чи стоїть страва десь у меню (дні чи шаблони) або в замовленнях.
     *
     * FK на dish_id в menu_section_dishes / menu_template_section_dishes /
     * order_lines навмисно restrictOnDelete — фізичне видалення такої страви
     * MySQL відхилить, щоб не зламати історію меню й замовлень.
     */
    public function isInUse(): bool
    {
        return $this->menuSectionDishes()->exists()
            || $this->menuTemplateSectionDishes()->exists()
            || $this->orderLines()->exists();
    }

    public function menuSectionDishes(): HasMany
    {
        return $this->hasMany(MenuSectionDish::class);
    }

    public function menuTemplateSectionDishes(): HasMany
    {
        return $this->hasMany(MenuTemplateSectionDish::class);
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** Зміни назви й ціни страви — ключове для журналу (ТЗ, п. 13). */
    protected function activityAttributes(): array
    {
        return ['name', 'price', 'portion', 'is_archived'];
    }

    protected static function activityLabel(): string
    {
        return 'Страва';
    }

    public function activitySupplierId(): int
    {
        return $this->supplier_id;
    }
}
