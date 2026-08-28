<?php

namespace App\Models;

use App\Enums\MenuSectionType;
use App\Enums\OrderLineStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLine extends Model
{
    protected $fillable = [
        'order_id',
        'student_id',
        'supplier_id',
        'service_date',
        'dish_id',
        'menu_section_id',
        'dish_name',
        'section_type',
        'section_title',
        'quantity',
        'unit_price',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected function casts(): array
    {
        return [
            'service_date' => 'date',
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'section_type' => MenuSectionType::class,
            'status' => OrderLineStatus::class,
            'cancelled_at' => 'datetime',
            // Ключі кастуємо явно: на деяких збірках PDO вони приходять рядками,
            // і строге порівняння з id моделі мовчки не спрацьовує.
            'order_id' => 'integer',
            'student_id' => 'integer',
            'supplier_id' => 'integer',
            'dish_id' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function dish(): BelongsTo
    {
        return $this->belongsTo(Dish::class);
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', OrderLineStatus::Active);
    }

    public function scopeForServiceDate(Builder $query, mixed $date): Builder
    {
        return $query->whereDate('service_date', $date);
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderLineStatus::Cancelled;
    }

    public function subtotal(): float
    {
        return (float) $this->unit_price * $this->quantity;
    }
}
