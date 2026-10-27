<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'login',
        'email',
        'password',
        'must_change_password',
        'role',
        'supplier_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'plain_password',
        'remember_token',
    ];

    /** Дублює default з міграції, щойно створений об'єкт теж має бути активним. */
    protected $attributes = [
        'is_active' => true,
    ];

    /** Пароль у відкритому вигляді з останнього встановлення — живе лише до збереження. */
    private ?string $pendingPlainPassword = null;

    protected static function booted(): void
    {
        // Адміністратор має бачити пароль учня в картці, тож для учнів
        // зберігаємо його ще й зашифрованим (див. plain_password). Для інших ролей
        // і для пароля, переданого вже хешем, не зберігаємо: його не відновити.
        static::saving(function (User $user): void {
            if ($user->isDirty('password')) {
                $user->plain_password = $user->isStudent() ? $user->pendingPlainPassword : null;
                $user->pendingPlainPassword = null;
            }
        });
    }

    /**
     * Хешує пароль, як це робив каст «hashed», і запам'ятовує відкритий
     * текст для plain_password. Уже готовий хеш пропускається як є.
     */
    protected function password(): Attribute
    {
        return Attribute::make(set: function (?string $value): ?string {
            if ($value === null || Hash::isHashed($value)) {
                $this->pendingPlainPassword = null;

                return $value;
            }

            $this->pendingPlainPassword = $value;

            return Hash::make($value);
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'plain_password' => 'encrypted',
            'must_change_password' => 'boolean',
            'role' => UserRole::class,
            'is_active' => 'boolean',
        ];
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isSupplier(): bool
    {
        return $this->role === UserRole::Supplier;
    }

    public function isStudent(): bool
    {
        return $this->role === UserRole::Student;
    }

    /**
     * Розмежування панелей: адміністратор школи не потрапляє в кабінет постачальника
     * і навпаки, учень не потрапляє в жодну з них.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'admin' => $this->isAdmin(),
            'supplier' => $this->isSupplier() && $this->supplier_id !== null,
            default => false,
        };
    }
}
