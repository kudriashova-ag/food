<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ТЗ, п. 3.2: акаунтом фактично користуються батьки, тому при першому вході
 * показуємо інформацію про обробку персональних даних і фіксуємо згоду.
 */
class EnsureConsentGiven
{
    public function handle(Request $request, Closure $next): Response
    {
        $student = $request->user()?->student;

        if ($student !== null && ! $student->hasConsented()) {
            return redirect()->route('consent.show');
        }

        return $next($request);
    }
}
