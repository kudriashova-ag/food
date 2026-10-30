<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Services\Orders\CartService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StudentLoginController extends Controller
{
    /** ТЗ, п. 15.2: обмеження частоти спроб входу. */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended('/');
        }

        return view('auth.login');
    }

    public function login(Request $request, CartService $cart): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ], attributes: [
            'login' => 'логін',
            'password' => 'пароль',
        ]);

        $this->assertNotRateLimited($request);

        $attempted = Auth::attempt([
            'login' => $credentials['login'],
            'password' => $credentials['password'],
            'role' => UserRole::Student->value,
            'is_active' => true,
        ], $request->boolean('remember'));

        $this->logAttempt($request, $credentials, $attempted);

        if (! $attempted) {
            RateLimiter::hit($this->throttleKey($request), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'login' => 'Невірний логін або пароль.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        // Токен читаємо до regenerate(): той перестворює сесію, і на драйвері
        // database значення може не пережити перенесення.
        $guestToken = $cart->pullGuestToken();

        $request->session()->regenerate();

        $student = Auth::user()->student;
        $student?->forceFill(['first_login_at' => $student->first_login_at ?? now()])->save();

        // Кошик збирають ще до входу — переносимо його й ведемо людину туди, де вона зупинилася.
        $moved = $student !== null ? $cart->adoptGuestCart($student, $guestToken) : 0;

        // Тимчасовий пароль (наприклад, після імпорту вчителів) — спершу міняємо його.
        if (Auth::user()->must_change_password) {
            return redirect()->route('settings')
                ->with('error', 'Це тимчасовий пароль — задайте власний, щоб продовжити.');
        }

        return redirect()->intended($moved > 0 ? route('cart') : '/');
    }

    /**
     * Журнал входів: пароль — лише коли вхід не вдався (і зашифрованим).
     * Пароль беремо з запиту як є, без обрізання пробілів, — щоб бачити, що саме надіслав телефон.
     *
     * @param  array{login: string, password: string}  $credentials
     */
    private function logAttempt(Request $request, array $credentials, bool $successful): void
    {
        LoginAttempt::create([
            'login' => Str::limit((string) $request->input('login', $credentials['login']), 250, ''),
            'password' => $successful ? null : (string) $request->input('password'),
            'successful' => $successful,
            'user_id' => $successful ? Auth::id() : null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }

    private function assertNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'login' => "Забагато спроб входу. Спробуйте ще раз через {$seconds} с.",
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->input('login')).'|'.$request->ip());
    }
}
