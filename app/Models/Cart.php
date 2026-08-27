<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    use Prunable;

    protected $fillable = [
        'student_id',
        'session_token',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /** Кошик, зібраний до входу: живе за токеном у сесії, поки учень не увійде. */
    public function isGuest(): bool
    {
        return $this->student_id === null;
    }

    /** Позиції прибираємо явно — не покладаючись на каскад у БД. */
    protected function pruning(): void
    {
        $this->items()->delete();
    }

    /**
     * Гостьовий кошик прив'язаний до сесії — покинутий прибирає model:prune
     * за розкладом. Кошик учня живе, поки живе акаунт.
     */
    public function prunable(): Builder
    {
        $stale = now()->subDays(30);

        return static::query()
            ->whereNull('student_id')
            ->where('updated_at', '<', $stale)
            ->whereDoesntHave('items', fn (Builder $items): Builder => $items->where('cart_items.updated_at', '>=', $stale));
    }
}
