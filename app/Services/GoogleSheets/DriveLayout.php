<?php

namespace App\Services\GoogleSheets;

use App\Models\GoogleDriveItem;
use App\Models\Supplier;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Структура на Google Диску: Food / {Постачальник} / «{Постачальник} з 19.10 до 23.10».
 *
 * ID створених папок і файлів зберігаються в google_drive_items. Якщо щось
 * видалили з Диска, створюємо заново; перейменованого постачальника
 * перейменовуємо й на Диску.
 */
class DriveLayout
{
    public const ROOT_NAME = 'Food';

    public function __construct(private readonly GoogleSheetsClient $client) {}

    public static function weekKey(Supplier $supplier, CarbonInterface $monday): string
    {
        return "week:{$supplier->id}:".OrdersWorkbook::weekStart($monday)->toDateString();
    }

    /** Посилання на папку Food; null — сайт її ще не створював. Без звернення до Google. */
    public static function rootFolderUrl(): ?string
    {
        return static::folderUrl('root');
    }

    /** Посилання на папку постачальника; null — ще не створена. Без звернення до Google. */
    public static function supplierFolderUrl(Supplier $supplier): ?string
    {
        return static::folderUrl("supplier:{$supplier->id}");
    }

    private static function folderUrl(string $key): ?string
    {
        $id = GoogleDriveItem::query()->where('key', $key)->value('drive_id');

        return $id === null ? null : 'https://drive.google.com/drive/folders/'.rawurlencode($id);
    }

    /** Чи сайт уже створював файл цього тижня (без звернення до Google). */
    public function hasWeekSpreadsheet(Supplier $supplier, CarbonInterface $monday): bool
    {
        return GoogleDriveItem::query()->where('key', static::weekKey($supplier, $monday))->exists();
    }

    /** Папка Food — створюється за потреби (для перевірки з'єднання). */
    public function rootFolder(): string
    {
        return Cache::lock('google-drive-layout', 120)->block(60, fn (): string => $this->ensure(
            'root', self::ROOT_NAME, GoogleSheetsClient::FOLDER_MIME, null,
        ));
    }

    /** ID таблиці тижня; папки й файл створюються за потреби. */
    public function weekSpreadsheet(Supplier $supplier, CarbonInterface $monday): string
    {
        // Завдання з черги можуть прийти паралельно — без замка вийшло б два файли.
        return Cache::lock('google-drive-layout', 120)->block(60, function () use ($supplier, $monday): string {
            $root = $this->ensure('root', self::ROOT_NAME, GoogleSheetsClient::FOLDER_MIME, null);
            $folder = $this->ensure("supplier:{$supplier->id}", static::safeName($supplier->name), GoogleSheetsClient::FOLDER_MIME, $root);

            return $this->ensure(
                static::weekKey($supplier, $monday),
                OrdersWorkbook::fileTitle($supplier, $monday),
                GoogleSheetsClient::SPREADSHEET_MIME,
                $folder,
            );
        });
    }

    private function ensure(string $key, string $name, string $mimeType, ?string $parentId): string
    {
        $item = GoogleDriveItem::query()->where('key', $key)->first();

        if ($item !== null) {
            $file = $this->client->findFile($item->drive_id);

            if ($file !== null) {
                if ($file['name'] !== $name) {
                    $this->client->renameFile($file['id'], $name);
                }

                return $file['id'];
            }

            // Видалили з Диска (або в кошику) — створимо заново.
            $item->delete();
        }

        $id = $this->client->createFile($name, $mimeType, $parentId);

        GoogleDriveItem::query()->updateOrCreate(['key' => $key], ['drive_id' => $id]);

        return $id;
    }

    /** Назва без символів, які плутають людей у шляхах. */
    public static function safeName(string $name): string
    {
        return trim(preg_replace('~[\\\\/:*?"<>|]+~u', ' ', $name) ?? $name);
    }
}
