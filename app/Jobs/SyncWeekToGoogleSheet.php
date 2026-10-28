<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\OrdersWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Перебудовує в Google Таблиці вкладку «постачальник + тиждень».
 *
 * Унікальна до початку обробки: серія замовлень за хвилину дає одне оновлення,
 * а зміна, що прийшла вже під час обробки, поставить ще одне — нічого не загубиться.
 */
class SyncWeekToGoogleSheet implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $backoff = 60;

    public function __construct(
        public int $supplierId,
        public string $weekStart,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->supplierId}:{$this->weekStart}";
    }

    public function handle(OrdersWorkbook $workbook): void
    {
        $supplier = Supplier::find($this->supplierId);

        if ($supplier === null || ! GoogleSheetsClient::isConfigured()) {
            return;
        }

        $workbook->writeWeek($supplier, CarbonImmutable::parse($this->weekStart));
    }
}
