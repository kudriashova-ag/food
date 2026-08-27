<?php

namespace App\Exports;

use App\Models\Supplier;
use App\Services\Reports\KitchenReportService;
use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/** Обидва звіти для кухні одним файлом: зведення на день і список для видачі. */
class KitchenReportExport implements Export, WithMultipleSheets
{
    use Exportable;

    public function __construct(
        private readonly Supplier $supplier,
        private readonly CarbonImmutable $date,
        private readonly KitchenReportService $reports,
    ) {}

    public function sheets(): array
    {
        return [
            new KitchenSummarySheet($this->reports->dailySummary($this->supplier, $this->date), $this->date),
            new KitchenHandoutSheet($this->reports->handoutList($this->supplier, $this->date), $this->date),
        ];
    }
}
