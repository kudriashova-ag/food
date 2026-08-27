<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Обслуговування без SSH.
 *
 * Хостинг не дає shell, тому міграції й перебудову кешу доводиться
 * запускати через браузер. Це потенційно небезпечний маршрут, тому:
 *
 *   1. вмикається лише коли в .env заданий MAINTENANCE_TOKEN;
 *   2. токен звіряється порівнянням, стійким до підбору за часом;
 *   3. обмежена частота спроб;
 *   4. список команд закритий — виконати можна тільки те, що тут перелічено.
 *
 * Після запуску сайту рядок MAINTENANCE_TOKEN варто прибрати з .env —
 * тоді маршрут зникає зовсім.
 */
class MaintenanceController extends Controller
{
    /** Дозволені команди. Нічого поза цим списком виконати не можна. */
    private const COMMANDS = [
        'migrate' => ['migrate', ['--force' => true]],
        'seed' => ['db:seed', ['--force' => true, '--class' => 'DatabaseSeeder']],
        'storage-link' => ['storage:link', []],
        'cache' => ['optimize', []],
        'cache-clear' => ['optimize:clear', []],
        'filament-assets' => ['filament:assets', []],
        'schedule' => ['schedule:run', []],
        'queue' => ['queue:work', ['--stop-when-empty' => true, '--tries' => 3]],
        'status' => ['migrate:status', []],
    ];

    public function __invoke(Request $request, string $token, string $command): mixed
    {
        $this->assertEnabled();
        $this->assertNotRateLimited($request);
        $this->assertTokenMatches($token, $request);

        abort_unless(array_key_exists($command, self::COMMANDS), 404);

        [$artisanCommand, $parameters] = self::COMMANDS[$command];

        $exitCode = Artisan::call($artisanCommand, $parameters);

        return response(
            sprintf(
                "$ php artisan %s\n\n%s\n%s",
                $artisanCommand,
                Artisan::output(),
                $exitCode === 0 ? '— виконано успішно' : "— завершилося з кодом {$exitCode}",
            ),
            $exitCode === 0 ? 200 : 500,
        )->header('Content-Type', 'text/plain; charset=utf-8');
    }

    private function assertEnabled(): void
    {
        // Немає токена в .env — маршруту не існує.
        abort_if(blank(config('school.maintenance_token')), 404);
    }

    private function assertTokenMatches(string $token, Request $request): void
    {
        if (! hash_equals((string) config('school.maintenance_token'), $token)) {
            RateLimiter::hit($this->throttleKey($request), 300);

            abort(404);
        }

        RateLimiter::clear($this->throttleKey($request));
    }

    private function assertNotRateLimited(Request $request): void
    {
        abort_if(RateLimiter::tooManyAttempts($this->throttleKey($request), 5), 429);
    }

    private function throttleKey(Request $request): string
    {
        return 'maintenance:'.$request->ip();
    }
}
