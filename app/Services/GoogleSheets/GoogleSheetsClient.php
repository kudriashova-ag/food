<?php

namespace App\Services\GoogleSheets;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Мінімальний клієнт Google Sheets і Drive API від імені підключеного акаунта.
 *
 * Без google/apiclient: на шаред-хостингу простіше обійтися кількома запитами,
 * ніж тягнути десятки мегабайт залежностей.
 */
class GoogleSheetsClient
{
    private const SHEETS_API = 'https://sheets.googleapis.com/v4/spreadsheets';

    private const DRIVE_API = 'https://www.googleapis.com/drive/v3/files';

    public const FOLDER_MIME = 'application/vnd.google-apps.folder';

    public const SPREADSHEET_MIME = 'application/vnd.google-apps.spreadsheet';

    /** Чи можна писати на Диск — без підключення синхронізація просто вимкнена. */
    public static function isConfigured(): bool
    {
        return GoogleAuth::isConnected();
    }

    // ---------- Drive ----------

    /** Створює папку чи таблицю на Диску й повертає її ID. */
    public function createFile(string $name, string $mimeType, ?string $parentId = null): string
    {
        return (string) $this->request()
            ->post(self::DRIVE_API.'?fields=id', array_filter([
                'name' => $name,
                'mimeType' => $mimeType,
                'parents' => $parentId === null ? null : [$parentId],
            ]))
            ->throw()
            ->json('id');
    }

    /**
     * Папка чи файл на Диску; null — видалено назавжди або в кошику.
     *
     * @return array{id: string, name: string}|null
     */
    public function findFile(string $fileId): ?array
    {
        try {
            $file = $this->request()
                ->get(self::DRIVE_API.'/'.rawurlencode($fileId), ['fields' => 'id,name,trashed'])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            if (in_array($e->response->status(), [403, 404], true)) {
                return null;
            }

            throw $e;
        }

        return ($file['trashed'] ?? false) ? null : ['id' => (string) $file['id'], 'name' => (string) $file['name']];
    }

    public function renameFile(string $fileId, string $name): void
    {
        $this->request()
            ->patch(self::DRIVE_API.'/'.rawurlencode($fileId).'?fields=id', ['name' => $name])
            ->throw();
    }

    // ---------- Sheets ----------

    /**
     * Вкладки таблиці.
     *
     * @return array<int, array{sheetId: int, title: string, index: int}>
     */
    public function sheets(string $spreadsheetId): array
    {
        $response = $this->request()
            ->get($this->sheetsUrl($spreadsheetId), ['fields' => 'sheets.properties(sheetId,title,index)'])
            ->throw()
            ->json();

        return collect($response['sheets'] ?? [])
            ->map(fn (array $sheet): array => [
                'sheetId' => (int) ($sheet['properties']['sheetId'] ?? 0),
                'title' => (string) $sheet['properties']['title'],
                'index' => (int) ($sheet['properties']['index'] ?? 0),
            ])
            ->all();
    }

    /**
     * Значення й колір фону кожної клітинки вкладки — щоб зберегти кольори
     * й дані, які людина задала вручну, під час повного перезапису.
     * entered — введене значення як є (число лишається числом).
     *
     * @return array<int, array<int, array{value: string, entered: string|int|float|bool|null, color: array<string, float>|null}>>
     */
    public function gridColors(string $spreadsheetId, string $sheetTitle): array
    {
        $response = $this->request()
            ->get($this->sheetsUrl($spreadsheetId), [
                'ranges' => "'".str_replace("'", "''", $sheetTitle)."'",
                'includeGridData' => 'true',
                'fields' => 'sheets.data.rowData.values(formattedValue,userEnteredValue,userEnteredFormat.backgroundColor)',
            ])
            ->throw()
            ->json();

        return collect(data_get($response, 'sheets.0.data.0.rowData', []))
            ->map(fn (array $row): array => collect($row['values'] ?? [])
                ->map(fn (array $cell): array => [
                    'value' => (string) ($cell['formattedValue'] ?? ''),
                    'entered' => collect($cell['userEnteredValue'] ?? [])->first(),
                    'color' => $cell['userEnteredFormat']['backgroundColor'] ?? null,
                ])
                ->all())
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, mixed>
     */
    public function batchUpdate(string $spreadsheetId, array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        return $this->request()
            ->post($this->sheetsUrl($spreadsheetId, ':batchUpdate'), ['requests' => $requests])
            ->throw()
            ->json() ?? [];
    }

    public function clearValues(string $spreadsheetId, string $sheetTitle): void
    {
        // Тіло обов'язково має бути об'єктом «{}»: порожній масив Laravel кодує як «[]»,
        // і Google відповідає 400 «Root element must be a message».
        $this->request()
            ->withBody('{}', 'application/json')
            ->post($this->sheetsUrl($spreadsheetId, '/values/'.$this->range($sheetTitle).':clear'))
            ->throw();
    }

    /** @param  array<int, array<int, mixed>>  $values */
    public function updateValues(string $spreadsheetId, string $sheetTitle, array $values): void
    {
        $this->request()
            ->put($this->sheetsUrl($spreadsheetId, '/values/'.$this->range($sheetTitle).'?valueInputOption=RAW'), ['values' => $values])
            ->throw();
    }

    /** @param  array<int, array<int, mixed>>  $values */
    public function appendValues(string $spreadsheetId, string $sheetTitle, array $values): void
    {
        if ($values === []) {
            return;
        }

        $this->request()
            ->post(
                $this->sheetsUrl($spreadsheetId, '/values/'.$this->range($sheetTitle).':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS'),
                ['values' => $values],
            )
            ->throw();
    }

    private function request(): PendingRequest
    {
        return Http::withToken(GoogleAuth::accessToken())
            ->acceptJson()
            ->timeout(30)
            // Повторюємо лише збої мережі й сервера Google; 4xx — відповідь по суті.
            ->retry(2, 1000, fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()), throw: false);
    }

    private function sheetsUrl(string $spreadsheetId, string $suffix = ''): string
    {
        return self::SHEETS_API.'/'.rawurlencode($spreadsheetId).$suffix;
    }

    /** Діапазон «уся вкладка»: назва в лапках, апостроф подвоюється. */
    private function range(string $sheetTitle): string
    {
        return rawurlencode("'".str_replace("'", "''", $sheetTitle)."'");
    }
}
