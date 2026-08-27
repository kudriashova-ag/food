<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Traits\LogsActivity;

class Student extends Model
{
    use LogsActivity, RecordsActivity;

    protected $fillable = [
        'user_id',
        'full_name',
        'school_class_id',
        'is_active',
        'consent_at',
        'consent_ip',
        'first_login_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'consent_at' => 'datetime',
            'first_login_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function telegramLinks(): HasMany
    {
        return $this->hasMany(TelegramLink::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasConsented(): bool
    {
        return $this->consent_at !== null;
    }

    /** Чи є куди слати: пошта або хоч одна активна прив'язка Telegram. */
    public function isNotifiable(): bool
    {
        return filled($this->user?->email)
            || $this->telegramLinks()->where('is_active', true)->exists();
    }

    /** Дії адміністратора з акаунтами фіксуються в журналі (ТЗ, п. 13). */
    protected function activityAttributes(): array
    {
        return ['full_name', 'school_class_id', 'is_active', 'consent_at'];
    }

    protected static function activityLabel(): string
    {
        return 'Учень';
    }
}
