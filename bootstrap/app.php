<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'student' => App\Http\Middleware\EnsureStudent::class,
            'consent' => App\Http\Middleware\EnsureConsentGiven::class,
        ]);

        // Telegram не має нашого CSRF-токена; вебхук захищений секретом в адресі.
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// Кореневу папку сайту названо public_html — так її очікує більшість хостингів.
$app->usePublicPath(dirname(__DIR__).DIRECTORY_SEPARATOR.'public_html');

return $app;
