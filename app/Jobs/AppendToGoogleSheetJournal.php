<?php

namespace App\Jobs;

use App\Models\Supplier;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\OrdersWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Дописує рядки у вкладку «Журнал змін» файлу тижня постачальника на Google Диску. */
class AppendToGoogleSheetJournal implements ShouldQueue
{
    use Queueable;

    public int $backoff = 60;

    /** @param  array<int, array<int, string|int|float|null>>  $rows */
    public function __construct(
        public int $supplierId,
        public string $weekStart,
        public array $rows,
    ) {}

    public function handle(OrdersWorkbook $workbook): void
    {
        $supplier = Supplier::find($this->supplierId);

        if ($supplier === null || ! GoogleSheetsClient::isConfigured()) {
            return;
        }

        $workbook->appendJournal($supplier, CarbonImmutable::parse($this->weekStart), $this->rows);
    }
}
