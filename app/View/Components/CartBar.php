<?php

namespace App\View\Components;

use App\Services\Orders\CartService;
use Illuminate\View\Component;
use Illuminate\View\View;

/** Сума кошика закріплена внизу екрана й видима постійно (ТЗ, п. 14). */
class CartBar extends Component
{
    public int $count = 0;

    public float $total = 0;

    public function __construct(CartService $cart)
    {
        $current = $cart->currentIfExists();

        $this->count = $cart->count($current);
        $this->total = $cart->total($current);
    }

    public function render(): View
    {
        return view('components.cart-bar');
    }
}
