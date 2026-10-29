<?php

namespace App\Services\GoogleSheets;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Доступ до Google Диска від імені акаунта школи (OAuth).
 *
 * Сервісний акаунт тут не годиться: власного місця на Диску в нього немає,
 * і створювати файли він не може. Тож адміністратор один раз входить у Google,
 * а сайт зберігає refresh-токен (зашифрованим) і далі сам оновлює доступ.
 *
 * Дозвіл drive.file — лише до файлів, які створив сам сайт, а не до всього Диска.
 */
class GoogleAuth
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/drive.file';

    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /** Чи задано OAuth-клієнт у .env — без нього кнопка «Підключити» не має сенсу. */
    public static function hasClient(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    public static function isConnected(): bool
    {
        return static::hasClient() && filled(Setting::get('google_refresh_token'));
    }

    /** Google-акаунт, від імені якого створюються файли. */
    public static function accountEmail(): ?string
    {
        return Setting::get('google_account_email') ?: null;
    }

    public static function redirectUri(): string
    {
        return route('google.callback');
    }

    public static function authUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => static::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            // offline + consent: Google видасть refresh-токен навіть при повторному підключенні.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /** Обмінює код із callback на токени й запам'ятовує підключення. */
    public static function connect(string $code): void
    {
        $response = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => static::redirectUri(),
            'grant_type' => 'authorization_code',
        ])->throw()->json();

        $refreshToken = $response['refresh_token'] ?? null;

        if (blank($refreshToken)) {
            throw new RuntimeException('Google не видав refresh-токен. Відкличте доступ сайту в налаштуваннях Google-акаунта й підключіть ще раз.');
        }

        Setting::put('google_refresh_token', Crypt::encryptString($refreshToken));
        Setting::put('google_account_email', static::emailFromIdToken($response['id_token'] ?? null) ?? '');

        Cache::put(static::cacheKey(), $response['access_token'], now()->addSeconds(max(60, ($response['expires_in'] ?? 3600) - 300)));
    }

    public static function disconnect(): void
    {
        $refreshToken = static::refreshToken();

        if ($refreshToken !== null) {
            // Відкликаємо доступ і в Google; збій тут не заважає відключенню на сайті.
            rescue(fn () => Http::asForm()->timeout(15)->post(self::REVOKE_URL, ['token' => $refreshToken]), report: false);
        }

        Setting::put('google_refresh_token', '');
        Setting::put('google_account_email', '');
        Cache::forget(static::cacheKey());
    }

    public static function accessToken(): string
    {
        return Cache::remember(static::cacheKey(), now()->addMinutes(50), function (): string {
            $refreshToken = static::refreshToken() ?? throw new RuntimeException('Google Диск не підключено.');

            $token = Http::asForm()->timeout(30)->post(self::TOKEN_URL, [
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ])->throw()->json('access_token');

            if (! is_string($token) || $token === '') {
                throw new RuntimeException('Google не видав токен доступу.');
            }

            return $token;
        });
    }

    private static function refreshToken(): ?string
    {
        $stored = Setting::get('google_refresh_token');

        if (blank($stored)) {
            return null;
        }

        return rescue(fn (): string => Crypt::decryptString($stored), report: false);
    }

    /** E-mail з id_token: підпис не перевіряємо — токен прийшов напряму від Google по HTTPS. */
    private static function emailFromIdToken(?string $idToken): ?string
    {
        $payload = explode('.', (string) $idToken)[1] ?? null;

        if ($payload === null) {
            return null;
        }

        $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);

        return is_array($claims) ? ($claims['email'] ?? null) : null;
    }

    private static function cacheKey(): string
    {
        return 'google-drive-access-token';
    }
}
