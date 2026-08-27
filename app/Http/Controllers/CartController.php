<?php

namespace App\Http\Controllers;

use App\Exceptions\DeadlinePassedException;
use App\Exceptions\MenuUnavailableException;
use App\Models\CartItem;
use App\Models\MenuDay;
use App\Models\Supplier;
use App\Services\Orders\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CartController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    public function show(Request $request): View
    {
        $cart = $this->cart->currentIfExists();

        // Гість оформлює замовлення після входу — і має повернутися сюди ж, у свій кошик.
        if ($request->user() === null) {
            $request->session()->put('url.intended', route('cart'));
        }

        return view('cart', [
            'groups' => $this->cart->grouped($cart),
            'total' => $this->cart->total($cart),
            'expired' => $this->cart->expiredItems($cart),
        ]);
    }

    /** Додає вибір за один день цілком — щоб замовлення на тиждень робилося кількома дотиками. */
    public function storeDay(Request $request, Supplier $supplier, string $date): RedirectResponse
    {
        $cart = $this->cart->current();

        $menuDay = MenuDay::query()
            ->where('supplier_id', $supplier->id)
            ->whereDate('date', $date)
            ->with('sections')
            ->firstOrFail();

        $quantities = $request->input('qty', []);
        $choices = $request->input('choice', []);
        $choiceQuantities = $request->input('choice_qty', []);

        $added = 0;

        try {
            foreach ($menuDay->sections as $section) {
                foreach ($quantities[$section->id] ?? [] as $dishId => $quantity) {
                    if ((int) $quantity < 1) {
                        continue;
                    }

                    $this->cart->add($cart, $section, (int) $dishId, (int) $quantity);
                    $added++;
                }

                $chosen = $choices[$section->id] ?? null;

                if (! empty($chosen)) {
                    $this->cart->add(
                        $cart,
                        $section,
                        (int) $chosen,
                        max(1, (int) ($choiceQuantities[$section->id] ?? 1)),
                    );
                    $added++;
                }
            }
        } catch (DeadlinePassedException|MenuUnavailableException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($added === 0) {
            return back()->with('error', 'Нічого не обрано — позначте страви й спробуйте ще раз.');
        }

        return back()->with('status', "Додано в кошик: {$menuDay->date->translatedFormat('d.m.Y')}.");
    }

    public function updateItem(Request $request, CartItem $item): RedirectResponse
    {
        $this->authorizeItem($item);

        $this->cart->setQuantity($item, (int) $request->input('quantity', 1));

        return back();
    }

    public function destroyItem(Request $request, CartItem $item): RedirectResponse
    {
        $this->authorizeItem($item);

        $this->cart->remove($item);

        return back()->with('status', 'Позицію прибрано з кошика.');
    }

    /**
     * Чужий кошик недоступний навіть за прямим посиланням — ні учневі, ні гостю.
     *
     * Порівнюємо через (int): залежно від збірки PDO ключі приходять то числами,
     * то рядками, і строге порівняння "1" === 1 давало 403 власному ж кошику.
     */
    private function authorizeItem(CartItem $item): void
    {
        $current = $this->cart->currentIfExists();

        abort_unless($current !== null && (int) $item->cart_id === (int) $current->id, 403);
    }
}
