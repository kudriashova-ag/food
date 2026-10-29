<?php

namespace App\Http\Controllers;

use App\Filament\Pages\SchoolSettings;
use App\Services\GoogleSheets\GoogleAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Підключення Google Диска адміністратором: перехід на сторінку згоди Google
 * і повернення з кодом. Стан (state) звіряємо із сесією — від підробленого
 * повернення.
 */
class GoogleDriveController extends Controller
{
    public function connect(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        abort_unless(GoogleAuth::hasClient(), 404);

        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away(GoogleAuth::authUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->authorizeAdmin($request);

        $expected = $request->session()->pull('google_oauth_state');
        $settings = SchoolSettings::getUrl();

        if (! is_string($expected) || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect($settings)->with('google_error', 'Підключення не вдалося: сесія застаріла. Спробуйте ще раз.');
        }

        if ($request->filled('error') || ! $request->filled('code')) {
            return redirect($settings)->with('google_error', 'Підключення скасовано в Google.');
        }

        try {
            GoogleAuth::connect((string) $request->query('code'));
        } catch (\Throwable $e) {
            report($e);

            return redirect($settings)->with('google_error', 'Google не підтвердив підключення: '.$e->getMessage());
        }

        return redirect($settings)->with('google_connected', true);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->isAdmin(), 403);
    }
}
