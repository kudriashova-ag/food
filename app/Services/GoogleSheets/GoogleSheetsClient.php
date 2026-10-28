<?php

namespace App\Services\GoogleSheets;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Мінімальний клієнт Google Sheets API від імені сервісного акаунта.
 *
 * Без google/apiclient: на шаред-хостингу простіше обійтися двома сотнями рядків,
 * ніж тягнути десятки мегабайт залежностей. Токен — JWT, підписаний ключем
 * сервісного акаунта (RS256), обмінюється на access token і кешується.
 */
class GoogleSheetsClient
{
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    private const API = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    /** Чи є і ключ сервісного акаунта, і таблиця — без цього синхронізація просто вимкнена. */
    public static function isConfigured(): bool
    {
        return static::credentials() !== null && static::spreadsheetId() !== null;
    }

    /** ID таблиці з посилання, яке адміністратор вставив у «Налаштуваннях». */
    public static function spreadsheetId(): ?string
    {
        $value = trim((string) Setting::get('google_sheet_url', ''));

        if ($value === '') {
            return null;
        }

        if (preg_match('~/spreadsheets/d/([a-zA-Z0-9_-]+)~', $value, $matches)) {
            return $matches[1];
        }

        // Вставили не посилання, а сам ID.
        return preg_match('~^[a-zA-Z0-9_-]{20,}$~', $value) ? $value : null;
    }

    /** @return array{client_email: string, private_key: string, token_uri?: string}|null */
    public static function credentials(): ?array
    {
        $path = config('services.google_sheets.credentials');

        if (blank($path)) {
            return null;
        }

        if (! str_starts_with($path, '/') && ! preg_match('~^[A-Za-z]:[\\\\/]~', $path)) {
            $path = base_path($path);
        }

        if (! is_file($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['client_email'], $data['private_key']) ? $data : null;
    }

    /** E-mail, якому треба надати доступ до таблиці як «Редактор». */
    public static function serviceAccountEmail(): ?string
    {
        return static::credentials()['client_email'] ?? null;
    }

    /**
     * Вкладки таблиці.
     *
     * @return array<int, array{sheetId: int, title: string, index: int}>
     */
    public function sheets(): array
    {
        $response = $this->request()
            ->get($this->url(), ['fields' => 'properties.title,sheets.properties(sheetId,title,index)'])
            ->throw()
            ->json();

        return collect($response['sheets'] ?? [])
            ->map(fn (array $sheet): array => [
                'sheetId' => (int) $sheet['properties']['sheetId'],
                'title' => (string) $sheet['properties']['title'],
                'index' => (int) ($sheet['properties']['index'] ?? 0),
            ])
            ->all();
    }

    /** Назва самої таблиці — для перевірки з'єднання. */
    public function title(): string
    {
        return (string) $this->request()
            ->get($this->url(), ['fields' => 'properties.title'])
            ->throw()
            ->json('properties.title');
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, mixed>
     */
    public function batchUpdate(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        return $this->request()
            ->post($this->url(':batchUpdate'), ['requests' => $requests])
            ->throw()
            ->json() ?? [];
    }

    public function clearValues(string $sheetTitle): void
    {
        $this->request()
            ->post($this->url('/values/'.$this->range($sheetTitle).':clear'))
            ->throw();
    }

    /** @param  array<int, array<int, mixed>>  $values */
    public function updateValues(string $sheetTitle, array $values): void
    {
        $this->request()
            ->put($this->url('/values/'.$this->range($sheetTitle).'?valueInputOption=RAW'), ['values' => $values])
            ->throw();
    }

    /** @param  array<int, array<int, mixed>>  $values */
    public function appendValues(string $sheetTitle, array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->request()
            ->post(
                $this->url('/values/'.$this->range($sheetTitle).':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS'),
                ['values' => $values],
            )
            ->throw();
    }

    private function request(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 1000, throw: false);
    }

    private function url(string $suffix = ''): string
    {
        $id = static::spreadsheetId() ?? throw new RuntimeException('Google Таблицю не вказано в налаштуваннях.');

        return self::API.'/'.$id.$suffix;
    }

    /** Діапазон «уся вкладка»: назва в лапках, апостроф подвоюється. */
    private function range(string $sheetTitle): string
    {
        return rawurlencode("'".str_replace("'", "''", $sheetTitle)."'");
    }

    private function accessToken(): string
    {
        $credentials = static::credentials() ?? throw new RuntimeException('Не знайдено ключ сервісного акаунта Google.');

        return Cache::remember(
            'google-sheets-token:'.md5($credentials['client_email']),
            now()->addMinutes(50),
            fn (): string => $this->fetchAccessToken($credentials),
        );
    }

    /** @param  array{client_email: string, private_key: string, token_uri?: string}  $credentials */
    private function fetchAccessToken(array $credentials): string
    {
        $tokenUri = $credentials['token_uri'] ?? self::DEFAULT_TOKEN_URI;
        $now = time();

        $segments = [
            $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
            $this->base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ])),
        ];

        $signature = '';

        if (! openssl_sign(implode('.', $segments), $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Не вдалося підписати запит ключем сервісного акаунта Google.');
        }

        $segments[] = $this->base64Url($signature);

        $token = Http::asForm()
            ->timeout(30)
            ->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ])
            ->throw()
            ->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Google не видав токен доступу.');
        }

        return $token;
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
