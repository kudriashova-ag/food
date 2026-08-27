<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'supplier_id',
        'service_date',
        'dish_id',
        'menu_section_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'quantity' => 'integer',
            // Ключі кастуємо явно: на деяких збірках PDO вони приходять рядками,
            // і строге порівняння з id моделі мовчки не спрацьовує.
            'cart_id' => 'integer',
            'supplier_id' => 'integer',
            'dish_id' => 'integer',
            'menu_section_id' => 'integer',
        ];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function menuSection(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class);
    }

    /** Ціна тягнеться з картки страви й фіксується лише при оформленні. */
    public function subtotal(): float
    {
        return (float) $this->dish->price * $this->quantity;
    }
}
