<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вчителі отримують фіксований пароль при імпорті (food-teacher-2026) —
 * поки must_change_password не скинуто формою на /settings, будь-яку
 * іншу сторінку кабінету замінюємо редиректом туди.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->must_change_password && ! $request->routeIs('settings*', 'logout')) {
            return redirect()->route('settings')
                ->with('error', 'Спочатку задайте власний пароль — вам видали тимчасовий.');
        }

        return $next($request);
    }
}
