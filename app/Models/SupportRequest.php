<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Питання з форми «Допомога».
 *
 * Зберігаємо навіть після успішної відправки: якщо пошта чи Telegram відмовили,
 * звернення все одно видно адміністратору в панелі.
 */
class SupportRequest extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'message',
        'ip',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
