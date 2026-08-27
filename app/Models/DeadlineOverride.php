<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;

/** Виняток на конкретну дату — має пріоритет над правилом дня тижня. */
class DeadlineOverride extends Model
{
    use LogsActivity, RecordsActivity;

    protected $fillable = [
        'supplier_id',
        'date',
        'order_deadline_at',
        'cancel_deadline_at',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'order_deadline_at' => 'datetime',
            'cancel_deadline_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ТЗ, п. 7.2: скасування не може закриватися раніше за замовлення. */
    public function cancelIsNotEarlierThanOrder(): bool
    {
        if ($this->order_deadline_at === null || $this->cancel_deadline_at === null) {
            return true;
        }

        return $this->cancel_deadline_at->greaterThanOrEqualTo($this->order_deadline_at);
    }

    protected function activityAttributes(): array
    {
        return ['date', 'order_deadline_at', 'cancel_deadline_at', 'reason'];
    }

    protected static function activityLabel(): string
    {
        return 'Виняток дедлайну';
    }

    public function activitySupplierId(): int
    {
        return $this->supplier_id;
    }
}
