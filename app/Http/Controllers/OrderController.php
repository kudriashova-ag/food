<?php

namespace App\Http\Controllers;

use App\Exceptions\DeadlinePassedException;
use App\Exceptions\EmptyCartException;
use App\Models\MenuDay;
use App\Models\NonWorkingDay;
use App\Models\OrderLine;
use App\Services\Deadlines\DeadlineService;
use App\Services\Orders\CartService;
use App\Services\Orders\OrderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrderController extends Controller
{
    public function store(Request $request, OrderService $orders): RedirectResponse
    {
        try {
            $order = $orders->placeFromCart($request->user()->student);
        } catch (EmptyCartException|DeadlinePassedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('orders.index')
            ->with('status', "Замовлення {$order->number} прийнято. Сума: ".number_format((float) $order->total_amount, 2, ',', ' ').' грн.');
    }

    /** «Повторити минулий тиждень» — ТЗ, п. 9.2. */
    public function repeatWeek(Request $request, CartService $cart): RedirectResponse
    {
        $source = CarbonImmutable::parse($request->input('source', 'last week'))->startOfWeek();
        $target = $source->addWeek();

        $result = $cart->repeatWeek($request->user()->student, $source, $target);

        if ($result['added'] === 0 && $result['unavailable'] === []) {
            return back()->with('error', 'За той тиждень немає активних замовлень.');
        }

        $message = "Перенесено позицій у кошик: {$result['added']}.";

        if ($result['unavailable'] !== []) {
            $message .= ' Недоступні: '.implode('; ', $result['unavailable']).'.';
        }

        return redirect()->route('cart')->with($result['unavailable'] === [] ? 'status' : 'error', $message);
    }

    public function index(Request $request, DeadlineService $deadlines): View
    {
        $student = $request->user()->student;

        $weekStart = CarbonImmutable::parse($request->query('week', 'today'))->startOfWeek();
        $weekEnd = $weekStart->addDays(6);

        $lines = OrderLine::query()
            ->where('student_id', $student->id)
            ->whereDate('service_date', '>=', $weekStart->toDateString())
            ->whereDate('service_date', '<=', $weekEnd->toDateString())
            ->with(['supplier', 'order'])
            ->orderBy('service_date')
            ->orderBy('supplier_id')
            ->get();

        // Порожній день має різні причини: свято, невиставлене меню або просто
        // незамовлене харчування. Учневі важливо бачити, що саме.
        $holidays = NonWorkingDay::titlesBetween($weekStart, $weekEnd);

        $workingDates = MenuDay::query()
            ->visibleToStudents()
            ->whereDate('date', '>=', $weekStart->toDateString())
            ->whereDate('date', '<=', $weekEnd->toDateString())
            ->pluck('date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->unique()
            ->all();

        $days = collect(range(0, 6))
            ->map(fn (int $offset): CarbonImmutable => $weekStart->addDays($offset))
            ->mapWithKeys(fn (CarbonImmutable $date): array => [
                $date->toDateString() => [
                    'date' => $date,
                    'holiday' => $holidays[$date->toDateString()] ?? null,
                    'isWorkingDay' => in_array($date->toDateString(), $workingDates, true),
                    'suppliers' => $lines
                        ->filter(fn (OrderLine $line): bool => $line->service_date->toDateString() === $date->toDateString())
                        ->groupBy('supplier_id')
                        ->map(fn ($supplierLines) => [
                            'supplier' => $supplierLines->first()->supplier,
                            'lines' => $supplierLines->values(),
                            'total' => $supplierLines->where('status', \App\Enums\OrderLineStatus::Active)
                                ->sum(fn (OrderLine $line): float => $line->subtotal()),
                            'deadline' => $deadlines->for($supplierLines->first()->supplier_id, $date),
                        ]),
                ],
            ]);

        return view('orders', [
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd,
            'days' => $days,
        ]);
    }
}
