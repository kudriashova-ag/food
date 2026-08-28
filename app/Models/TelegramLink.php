<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Прив'язка чату Telegram. Належить або учневі (сповіщення батькам),
 * або постачальнику (зведення для кухні) — залежно від того, яке поле заповнене.
 */
class TelegramLink extends Model
{
    protected $fillable = [
        'student_id',
        'supplier_id',
        'chat_id',
        'username',
        'is_active',
        'linked_at',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'linked_at' => 'datetime',
            'deactivated_at' => 'datetime',
            // Див. коментар в OrderLine: ключі мають бути числами.
            'student_id' => 'integer',
            'supplier_id' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function isSupplierChat(): bool
    {
        return $this->supplier_id !== null;
    }

    /** Викликається при помилці 403 — користувач заблокував бота. */
    public function deactivate(): void
    {
        $this->update([
            'is_active' => false,
            'deactivated_at' => now(),
        ]);
    }
}
