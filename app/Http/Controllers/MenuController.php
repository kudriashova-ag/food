<?php

namespace App\Http\Controllers;

use App\Models\MenuDay;
use App\Models\Supplier;
use App\Services\Deadlines\DeadlineService;
use App\Services\Orders\CartService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MenuController extends Controller
{
    public function __invoke(Request $request, Supplier $supplier, DeadlineService $deadlines, CartService $cart): View
    {
        abort_unless($supplier->is_visible, 404);

        $from = CarbonImmutable::today();
        $to = $from->addDays(config('school.menu_horizon_days'));

        $days = MenuDay::query()
            ->where('supplier_id', $supplier->id)
            ->visibleToStudents()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->with(['sections.sectionDishes.dish.primaryPhoto', 'sections.sectionDishes.dish.allergens'])
            ->orderBy('date')
            ->get();

        $range = $deadlines->forRange($supplier, $from, $to);

        // Розгорнутим показуємо найближчий день, який ще приймає замовлення:
        // дні із завершеним прийманням і дальші лишаються згорнутими.
        $nextOpen = $days->first(
            fn (MenuDay $day): bool => ($range[$day->date->toDateString()] ?? null)?->orderingOpen() ?? false,
        );

        return view('menu', [
            'supplier' => $supplier,
            'days' => $days,
            'deadlines' => $range,
            'expandedDate' => $nextOpen?->date->toDateString(),
            'datesInCart' => $cart->datesInCartFor($supplier),
            'datesOrdered' => $cart->datesOrderedFor($supplier, $request->user()?->student),
        ]);
    }
}
