<?php

namespace App\Exports;

use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Замовлення учнів чи вчителів за період, окремим аркушем на кожного
 * постачальника — суми на кожному аркуші стосуються лише його страв.
 */
class OrdersBySupplierExport implements Export, WithMultipleSheets
{
    use Exportable;

    /** @param  Collection<int, array{supplier: Supplier, rows: Collection}>  $reports */
    public function __construct(
        private readonly Collection $reports,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    public function sheets(): array
    {
        if ($this->reports->isEmpty()) {
            // Файл не може лишитися без жодного аркуша.
            return [new EmptyOrdersSheet()];
        }

        return $this->reports
            ->map(fn (array $report): OrdersBySupplierSheet => new OrdersBySupplierSheet(
                $report['supplier'],
                $report['rows'],
                $this->from,
                $this->to,
            ))
            ->all();
    }
}
