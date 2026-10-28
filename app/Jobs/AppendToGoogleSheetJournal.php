<?php

namespace App\Jobs;

use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\OrdersWorkbook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Дописує рядки у вкладку «Журнал змін» Google Таблиці. */
class AppendToGoogleSheetJournal implements ShouldQueue
{
    use Queueable;

    public int $backoff = 60;

    /** @param  array<int, array<int, string|int|float|null>>  $rows */
    public function __construct(public array $rows) {}

    public function handle(OrdersWorkbook $workbook): void
    {
        if (! GoogleSheetsClient::isConfigured()) {
            return;
        }

        $workbook->appendJournal($this->rows);
    }
}
