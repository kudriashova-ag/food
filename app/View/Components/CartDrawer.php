<?php

namespace App\View\Components;

use App\Services\Orders\CartService;
use Illuminate\Support\Collection;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Кошик-шухляда: плашка в шапці, за нею виїжджає повний вміст.
 *
 * Вміст рендериться сервером, тож без JS плашка просто веде на /cart —
 * сторінка лишається робочою.
 */
class CartDrawer extends Component
{
    public int $count = 0;

    public float $total = 0;

    public Collection $groups;

    public function __construct(CartService $cart)
    {
        $current = $cart->currentIfExists();

        $this->count = $cart->count($current);
        $this->total = $cart->total($current);
        $this->groups = $cart->grouped($current);
    }

    public function render(): View
    {
        return view('components.cart-drawer');
    }
}
