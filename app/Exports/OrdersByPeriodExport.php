<?php

namespace App\Exports;

use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Замовлення учнів чи вчителів за період: № / Прізвище і ім'я / Клас /
 * одна колонка на кожен день періоду (сума за день) / одна колонка на
 * кожного постачальника (сума за весь період) / Всього до сплати.
 * Останній рядок — загальна сума по кожній колонці.
 */
class OrdersByPeriodExport implements Export, FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    use Exportable;

    /**
     * @param  Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, by_supplier: array<int, float>, total: float}>  $rows
     * @param  Collection<int, Supplier>  $suppliers
     */
    public function __construct(
        private readonly Collection $rows,
        private readonly Collection $suppliers,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    public function headings(): array
    {
        return [
            '№',
            "Прізвище, ім'я",
            'Клас',
            ...$this->dates()->map(fn (CarbonImmutable $date): string => $date->format('d.m.Y'))->all(),
            ...$this->suppliers->map(fn (Supplier $supplier): string => $supplier->name)->all(),
            'Всього до сплати',
        ];
    }

    public function array(): array
    {
        $rows = $this->rows
            ->map(function (array $row): array {
                return [
                    $row['number'],
                    $row['full_name'],
                    $row['class'],
                    ...$this->dates()->map(fn (CarbonImmutable $date): float => $row['by_date'][$date->toDateString()] ?? 0)->all(),
                    ...$this->suppliers->map(fn (Supplier $supplier): float => $row['by_supplier'][$supplier->id] ?? 0)->all(),
                    $row['total'],
                ];
            })
            ->all();

        $rows[] = $this->totalRow();

        return $rows;
    }

    /** @return array<int, int|float|string> */
    private function totalRow(): array
    {
        $dateTotals = $this->dates()
            ->map(fn (CarbonImmutable $date): float => $this->rows->sum(fn (array $row): float => $row['by_date'][$date->toDateString()] ?? 0))
            ->all();

        $supplierTotals = $this->suppliers
            ->map(fn (Supplier $supplier): float => $this->rows->sum(fn (array $row): float => $row['by_supplier'][$supplier->id] ?? 0))
            ->all();

        return [
            '',
            'Разом',
            '',
            ...$dateTotals,
            ...$supplierTotals,
            $this->rows->sum('total'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = $sheet->getHighestColumn();
        $lastRow = $sheet->getHighestRow();

        // Шапка: жирний білий текст на темному фоні, той самий рядок закріплюємо,
        // щоб він лишався видимим при прокручуванні довгого списку.
        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
        ]);
        $sheet->freezePane('A2');

        // Останній рядок — «Разом»: виділяємо жирним, щоб не губився в списку.
        $sheet->getStyle("A{$lastRow}:{$lastColumn}{$lastRow}")->applyFromArray([
            'font' => ['bold' => true],
        ]);

        return [];
    }

    /** @return Collection<int, CarbonImmutable> */
    private function dates(): Collection
    {
        $dates = collect();

        for ($date = $this->from; $date->lessThanOrEqualTo($this->to); $date = $date->addDay()) {
            $dates->push($date);
        }

        return $dates;
    }
}
