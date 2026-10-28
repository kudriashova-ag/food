<?php

namespace App\Services\Orders;

use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Повне видалення замовлень адміністратором. Позиції йдуть разом із замовленням
 * (каскад у БД), тож у журнал дій записуємо склад заздалегідь — після видалення
 * дізнатися, що саме прибрали, більше ніде.
 */
class OrderDeletion
{
    public static function log(Order $order): void
    {
        $order->loadMissing('lines', 'student');

        activity()
            ->performedOn($order)
            ->causedBy(auth()->user())
            ->withProperties([
                'number' => $order->number,
                'student' => $order->student?->full_name,
                'total' => (string) $order->total_amount,
                'lines' => $order->lines->map(fn ($line): array => [
                    'date' => $line->service_date->toDateString(),
                    'supplier_id' => $line->supplier_id,
                    'dish' => $line->dish_name,
                    'quantity' => $line->quantity,
                    'price' => (string) $line->unit_price,
                    'status' => $line->status?->value,
                ])->all(),
            ])
            ->event('order_deleted')
            ->log("Замовлення {$order->number}: видалено");
    }

    /** @return int скільки замовлень видалено */
    public static function deleteAll(): int
    {
        $count = Order::query()->count();

        DB::transaction(function () use ($count): void {
            Order::query()->delete();

            activity()
                ->causedBy(auth()->user())
                ->withProperties(['count' => $count])
                ->event('orders_deleted_all')
                ->log("Видалено всі замовлення: {$count}");
        });

        return $count;
    }
}
