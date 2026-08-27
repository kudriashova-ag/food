<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;

class Supplier extends Model
{
    use LogsActivity, RecordsActivity;

    protected $fillable = [
        'name',
        'slug',
        'logo_path',
        'description',
        'contact_person',
        'phone',
        'report_emails',
        'digest_time',
        'digest_enabled',
        'cancellation_alerts_enabled',
        'is_visible',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'digest_enabled' => 'boolean',
            'cancellation_alerts_enabled' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function dishes(): HasMany
    {
        return $this->hasMany(Dish::class);
    }

    public function menuDays(): HasMany
    {
        return $this->hasMany(MenuDay::class);
    }

    public function menuTemplates(): HasMany
    {
        return $this->hasMany(MenuTemplate::class);
    }

    public function deadlineRules(): HasMany
    {
        return $this->hasMany(DeadlineRule::class);
    }

    public function deadlineOverrides(): HasMany
    {
        return $this->hasMany(DeadlineOverride::class);
    }

    public function orderLines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    /** Прихований постачальник не показується учням, але лишається у звітах. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('is_visible', true);
    }

    public function telegramLinks(): HasMany
    {
        return $this->hasMany(TelegramLink::class);
    }

    public function digests(): HasMany
    {
        return $this->hasMany(SupplierDigest::class);
    }

    /**
     * Адреси для звітів. Поле дозволяє кілька через кому;
     * якщо не заповнене — беремо пошту облікових записів постачальника.
     *
     * @return array<int, string>
     */
    public function reportRecipients(): array
    {
        $emails = collect(explode(',', (string) $this->report_emails))
            ->map(fn (string $email): string => trim($email))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        if ($emails->isNotEmpty()) {
            return $emails->unique()->values()->all();
        }

        return $this->users()
            ->whereNotNull('email')
            ->pluck('email')
            ->unique()
            ->values()
            ->all();
    }

    public function hasNotificationChannel(): bool
    {
        return $this->reportRecipients() !== []
            || $this->telegramLinks()->active()->exists();
    }

    protected function activityAttributes(): array
    {
        return ['name', 'is_visible', 'contact_person', 'phone', 'digest_time', 'digest_enabled'];
    }

    protected static function activityLabel(): string
    {
        return 'Постачальник';
    }

    public function activitySupplierId(): int
    {
        return $this->id;
    }
}
