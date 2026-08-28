<?php

use App\Http\Controllers\Auth\StudentLoginController;
use App\Http\Controllers\CancellationController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\SchedulerController;
use App\Http\Controllers\SupplierListController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/login', [StudentLoginController::class, 'show'])->name('login');
Route::post('/login', [StudentLoginController::class, 'login']);

Route::post('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect('/');
})->name('logout');

/*
 * Вітрина відкрита без входу: меню й кошик доступні гостю, вхід просимо
 * лише на оформленні замовлення. Гостьовий кошик прив'язаний до сесії
 * й переноситься в кошик учня одразу після входу.
 *
 * Middleware consent пропускає гостя далі, а учня без згоди веде на /consent.
 */
Route::middleware('consent')->group(function (): void {
    Route::get('/', SupplierListController::class)->name('home');

    Route::get('/suppliers/{supplier:slug}', MenuController::class)->name('menu');

    // Довідка відкрита всім: питання виникають і до входу.
    Route::get('/about', [SupportController::class, 'info'])->name('support.info');
    Route::get('/help', [SupportController::class, 'show'])->name('support.help');
    Route::post('/help', [SupportController::class, 'store'])->name('support.store');

    Route::get('/cart', [CartController::class, 'show'])->name('cart');
    Route::post('/cart/{supplier:slug}/{date}', [CartController::class, 'storeDay'])->name('cart.store-day');
    Route::patch('/cart/items/{item}', [CartController::class, 'updateItem'])->name('cart.update-item');
    Route::delete('/cart/items/{item}', [CartController::class, 'destroyItem'])->name('cart.destroy-item');
});

Route::middleware('student')->group(function (): void {
    Route::get('/consent', [ConsentController::class, 'show'])->name('consent.show');
    Route::post('/consent', [ConsentController::class, 'store'])->name('consent.store');

    Route::middleware('consent')->group(function (): void {
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::post('/orders/repeat-week', [OrderController::class, 'repeatWeek'])->name('orders.repeat-week');

        Route::delete('/orders/lines/{line}', [CancellationController::class, 'cancelLine'])->name('orders.cancel-line');
        Route::delete('/orders/{supplier:slug}/{date}', [CancellationController::class, 'cancelDay'])->name('orders.cancel-day');

        Route::get('/settings', [NotificationSettingsController::class, 'show'])->name('settings');
        Route::patch('/settings/email', [NotificationSettingsController::class, 'updateEmail'])->name('settings.email');
        Route::patch('/settings/password', [NotificationSettingsController::class, 'updatePassword'])->name('settings.password');
        Route::post('/settings/telegram', [NotificationSettingsController::class, 'connectTelegram'])->name('settings.telegram.connect');
        Route::delete('/settings/telegram/{link}', [NotificationSettingsController::class, 'disconnectTelegram'])->name('settings.telegram.disconnect');
    });
});

// Вебхук бота: без сесії й CSRF, захищений секретом в адресі.
Route::post('/telegram/webhook/{secret}', TelegramWebhookController::class)
    ->name('telegram.webhook');

/*
| Обслуговування без SSH.
|
| Обидва маршрути існують, лише поки в .env заданий відповідний токен.
| Порожній токен — і Laravel віддає 404, наче маршруту немає.
*/
Route::get('/maintenance/{token}/{command}', MaintenanceController::class)
    ->name('maintenance');

Route::get('/scheduler/{token}', SchedulerController::class)
    ->name('scheduler');
