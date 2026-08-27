<?php

namespace App\Http\Controllers;

use App\Services\Orders\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConsentController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $student = $request->user()->student;

        if ($student?->hasConsented()) {
            return redirect()->route('home');
        }

        return view('consent');
    }

    public function store(Request $request, CartService $cart): RedirectResponse
    {
        $request->validate(
            ['agreed' => ['accepted']],
            ['agreed.accepted' => 'Щоб користуватися сервісом, потрібно підтвердити згоду.'],
        );

        // Факт згоди фіксується з датою та IP (ТЗ, п. 3.2).
        $request->user()->student?->update([
            'consent_at' => now(),
            'consent_ip' => $request->ip(),
        ]);

        // Якщо згоду просили посеред оформлення — повертаємо людину в кошик.
        $hasItems = (bool) $cart->currentIfExists()?->items()->exists();

        return redirect()->route($hasItems ? 'cart' : 'home');
    }
}
