<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Журнал входів на сайт (сторінка /login). Для невдалої спроби зберігаємо
 * введений пароль — зашифрованим, щоб розбиратися, чому людина не може увійти
 * (автовиправлення телефона, зайвий пробіл). Записи старші за PRUNE_DAYS видаляються.
 */
class LoginAttempt extends Model
{
    use MassPrunable;

    public const PRUNE_DAYS = 30;

    public const UPDATED_AT = null;

    protected $fillable = ['login', 'password', 'successful', 'user_id', 'ip', 'user_agent'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'successful' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::PRUNE_DAYS));
    }

    /** «iPhone», «Android», «Windows»… — щоб одразу бачити пристрій. */
    public function device(): string
    {
        $agent = (string) $this->user_agent;

        return match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Macintosh') => 'Mac',
            str_contains($agent, 'Linux') => 'Linux',
            default => '—',
        };
    }
}
