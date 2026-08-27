<?php

namespace App\Services\Orders;

use App\Enums\OrderLineStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Student;
use App\Models\User;
use App\Notifications\OrderLinesCancelled;
use App\Services\Deadlines\DeadlineService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Скасування завжди гранулярне: окрема страва або окремий день,
 * ніколи не все замовлення цілком (ТЗ, п. 8.1).
 *
 * Позиція не видаляється — змінює статус, щоб лишитися в журналі та звітах.
 */
class CancellationService
{
    public function __construct(private readonly DeadlineService $deadlines) {}

    /**
     * @param  int|null  $quantity  Скільки порцій скасувати. null — усю позицію.
     * @param  bool  $bypassDeadline  Дозволено адміністратору школи, з обов'язковою причиною.
     * @param  bool  $notify  Вимикається при скасуванні дня, щоб піти одним листом.
     */
    public function cancelLine(
        OrderLine $line,
        User $actor,
        ?int $quantity = null,
        ?string $reason = null,
        bool $bypassDeadline = false,
        bool $notify = true,
    ): void {
        if ($line->isCancelled()) {
            return;
        }

        if (! $bypassDeadline) {
            $this->deadlines->assertCanCancel($line->supplier_id, $line->service_date);
        }

        $quantity = min($quantity ?? $line->quantity, $line->quantity);

        $cancelled = DB::transaction(function () use ($line, $actor, $quantity, $reason): OrderLine {
            if ($quantity < $line->quantity) {
                // Часткове скасування: решта лишається активною окремим рядком.
                $line->decrement('quantity', $quantity);

                $cancelled = $line->order->lines()->create([
                    ...$line->only([
                        'student_id', 'supplier_id', 'service_date', 'dish_id', 'menu_section_id',
                        'dish_name', 'section_type', 'section_title', 'unit_price',
                    ]),
                    'quantity' => $quantity,
                    'status' => OrderLineStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                    'cancel_reason' => $reason,
                ]);
            } else {
                $line->update([
                    'status' => OrderLineStatus::Cancelled,
                    'cancelled_at' => now(),
                    'cancelled_by' => $actor->id,
                    'cancel_reason' => $reason,
                ]);

                $cancelled = $line;
            }

            $line->order->recalculateTotal();

            return $cancelled;
        });

        $this->recordActivity($cancelled, $actor, $reason, $bypassDeadline);

        if ($notify) {
            $this->notify($line->student, collect([$cancelled]), $actor, $reason);
        }
    }

    /** Знімає всі позиції одного постачальника на одну дату. */
    public function cancelDay(
        Student $student,
        int $supplierId,
        CarbonInterface|string $date,
        User $actor,
        ?string $reason = null,
        bool $bypassDeadline = false,
    ): int {
        if (! $bypassDeadline) {
            $this->deadlines->assertCanCancel($supplierId, $date);
        }

        $lines = OrderLine::query()
            ->where('student_id', $student->id)
            ->where('supplier_id', $supplierId)
            ->whereDate('service_date', $date)
            ->active()
            ->get();

        foreach ($lines as $line) {
            $this->cancelLine($line, $actor, reason: $reason, bypassDeadline: true, notify: false);
        }

        if ($lines->isNotEmpty()) {
            $this->notify($student, $lines, $actor, $reason);
        }

        return $lines->count();
    }

    /** Чи можна ще скасувати цю позицію — для показу кнопки. */
    public function canCancel(OrderLine $line): bool
    {
        return ! $line->isCancelled()
            && $this->deadlines->canCancel($line->supplier_id, $line->service_date);
    }

    public function orderTotal(Order $order): float
    {
        return (float) $order->total_amount;
    }

    /** ТЗ, п. 13: хто, коли й чому скасував — фіксується незмінно. */
    private function recordActivity(OrderLine $line, User $actor, ?string $reason, bool $bypassDeadline): void
    {
        activity()
            ->performedOn($line)
            ->causedBy($actor)
            ->withProperties([
                'supplier_id' => $line->supplier_id,
                'order_number' => $line->order->number,
                'service_date' => $line->service_date->toDateString(),
                'dish' => $line->dish_name,
                'quantity' => $line->quantity,
                'reason' => $reason,
                'past_deadline' => $bypassDeadline,
            ])
            ->event('line_cancelled')
            ->log(sprintf(
                'Позицію скасовано: %s на %s%s',
                $line->dish_name,
                $line->service_date->translatedFormat('d.m.Y'),
                $bypassDeadline ? ' (поза дедлайном)' : '',
            ));
    }

    /** @param Collection<int, OrderLine> $lines */
    private function notify(?Student $student, Collection $lines, User $actor, ?string $reason): void
    {
        if ($student === null || ! $student->isNotifiable()) {
            return;
        }

        // Може прийти як звичайна колекція (одна позиція), тож підвантажуємо порядково.
        $lines->each(fn (OrderLine $line) => $line->loadMissing('supplier'));

        $student->user->notify(new OrderLinesCancelled(
            lines: $lines,
            byAdministrator: ! $actor->isStudent(),
            reason: $reason,
        ));
    }
}
