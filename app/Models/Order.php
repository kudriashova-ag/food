<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $fillable = [
        'number',
        'student_id',
        'school_class_id',
        'placed_at',
        'total_amount',
    ];

    protected function casts(): array
    {
        return [
            'placed_at' => 'datetime',
            'total_amount' => 'decimal:2',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** Сума активних позицій: скасування перераховує підсумок. */
    public function recalculateTotal(): void
    {
        $total = $this->lines()->active()->get()
            ->sum(fn (OrderLine $line): float => $line->subtotal());

        $this->update(['total_amount' => $total]);
    }
}
