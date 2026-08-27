<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Відносне правило дедлайну на день тижня.
 * offset_days — за скільки днів до дня харчування закривається приймання
 * (1 + 09:00 для понеділка = «нд, 09:00»; 0 + 09:00 = «того ж дня до 09:00»).
 */
class DeadlineRule extends Model
{
    use LogsActivity, RecordsActivity;

    protected $fillable = [
        'supplier_id',
        'weekday',
        'order_offset_days',
        'order_time',
        'cancel_offset_days',
        'cancel_time',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'order_offset_days' => 'integer',
            'cancel_offset_days' => 'integer',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * ТЗ, п. 7.2: дедлайн скасування може бути пізнішим за дедлайн замовлення, але не раніше.
     * Обидва відлічуються від тієї самої дати харчування, тож порівнюємо зсув і час.
     */
    public function cancelIsNotEarlierThanOrder(): bool
    {
        if ($this->cancel_offset_days !== $this->order_offset_days) {
            return $this->cancel_offset_days < $this->order_offset_days;
        }

        return $this->normalizeTime($this->cancel_time) >= $this->normalizeTime($this->order_time);
    }

    private function normalizeTime(mixed $time): string
    {
        return substr((string) $time, 0, 8);
    }

    protected function activityAttributes(): array
    {
        return ['weekday', 'order_offset_days', 'order_time', 'cancel_offset_days', 'cancel_time'];
    }

    protected static function activityLabel(): string
    {
        return 'Правило дедлайну';
    }

    public function activitySupplierId(): int
    {
        return $this->supplier_id;
    }
}
