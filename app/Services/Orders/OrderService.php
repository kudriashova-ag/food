<?php

namespace App\Services\Orders;

use App\Exceptions\EmptyCartException;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Student;
use App\Notifications\OrderPlaced;
use App\Services\Deadlines\DeadlineService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly CartService $cart,
        private readonly DeadlineService $deadlines,
    ) {}

    /**
     * Оформлення замовлення з кошика.
     *
     * Дедлайн перевіряється ще раз тут, на сервері (ТЗ, п. 15.2) — окремо для
     * кожної групи «постачальник + дата». Ціна й назва страви фіксуються
     * в позиції: подальші зміни в меню вже оформлене замовлення не чіпають.
     */
    public function placeFromCart(Student $student): Order
    {
        $cart = $this->cart->for($student);

        $items = $cart->items()
            ->with(['dish', 'menuSection', 'menuSection.sectionDishes.dish'])
            ->get();

        if ($items->isEmpty()) {
            throw EmptyCartException::make();
        }

        $this->assertAllGroupsAreOpen($items);

        $order = DB::transaction(function () use ($student, $cart, $items): Order {
            $order = Order::create([
                'number' => $this->nextNumber(),
                'student_id' => $student->id,
                'school_class_id' => $student->school_class_id,
                'placed_at' => now(),
                'total_amount' => 0,
            ]);

            foreach ($items as $item) {
                $isComplex = $item->menuSection?->type === \App\Enums\MenuSectionType::Complex;

                // Для комплексу: dish_name = складений рядок (назва секції + список страв),
                // unit_price = ціна секції. Для choice/extra: як звично по dish.price.
                $dishName = $isComplex
                    ? $this->formatComplexName($item->menuSection)
                    : $item->dish->name;

                $unitPrice = $isComplex
                    ? $item->menuSection->price
                    : $item->dish->price;

                $order->lines()->create([
                    'student_id' => $student->id,
                    'supplier_id' => $item->supplier_id,
                    'service_date' => $item->service_date,
                    'dish_id' => $item->dish_id,
                    'menu_section_id' => $item->menu_section_id,
                    'dish_name' => $dishName,
                    'section_type' => $item->menuSection?->type,
                    'section_title' => $item->menuSection?->title,
                    'quantity' => $item->quantity,
                    'unit_price' => $unitPrice,
                ]);
            }

            $order->recalculateTotal();

            $this->cart->clear($cart);

            return $order->refresh();
        });

        $this->recordActivity($order);

        $this->notifyStudent($student, new OrderPlaced($order));

        return $order;
    }

    /** ТЗ, п. 13: фіксуємо склад замовлення на момент створення — незмінно. */
    private function recordActivity(Order $order): void
    {
        activity()
            ->performedOn($order)
            ->causedBy(auth()->user())
            ->withProperties([
                'number' => $order->number,
                'total' => (string) $order->total_amount,
                'lines' => $order->lines->map(fn ($line): array => [
                    'date' => $line->service_date->toDateString(),
                    'supplier_id' => $line->supplier_id,
                    'dish' => $line->dish_name,
                    'quantity' => $line->quantity,
                    'price' => (string) $line->unit_price,
                ])->all(),
            ])
            ->event('order_placed')
            ->log("Замовлення {$order->number}: оформлено");
    }

    /** Лист ставиться в чергу й піде за розкладом, а не в момент HTTP-запиту (ТЗ, п. 12.3). */
    private function notifyStudent(Student $student, OrderPlaced $notification): void
    {
        if ($student->isNotifiable()) {
            $student->user->notify($notification);
        }
    }

    /** @param Collection<int, CartItem> $items */
    private function assertAllGroupsAreOpen(Collection $items): void
    {
        $items
            ->groupBy(fn (CartItem $item): string => $item->supplier_id.'|'.$item->service_date->toDateString())
            ->each(function (Collection $group): void {
                $first = $group->first();

                $this->deadlines->assertCanOrder($first->supplier_id, $first->service_date);
            });
    }

    /** Форматування назви комплексу для показу в замовленні: "Комплекс №1: Салат, Пюре, Котлета...". */
    private function formatComplexName($menuSection): string
    {
        $dishNames = $menuSection->sectionDishes
            ->map(fn ($sd) => $sd->dish?->name)
            ->filter()
            ->implode(', ');

        return "{$menuSection->title}: {$dishNames}";
    }

    /** ЗМ-20260817-0001 — щоденна нумерація, зрозуміла й людині, і в звіті. */
    private function nextNumber(): string
    {
        $prefix = config('school.order_number_prefix');
        $today = now()->format('Ymd');

        $countToday = Order::query()
            ->where('number', 'like', "{$prefix}-{$today}-%")
            ->count();

        return sprintf('%s-%s-%04d', $prefix, $today, $countToday + 1);
    }
}
