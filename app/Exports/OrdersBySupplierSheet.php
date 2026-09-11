<?php

namespace App\Exports;

use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Один аркуш — один постачальник: № / Прізвище, ім'я / Клас / по колонці
 * на кожен день періоду (сума за день у цього постачальника) / Разом.
 * Останній рядок — підсумок по кожній колонці.
 */
class OrdersBySupplierSheet implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /** @param  Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, total: float}>  $rows */
    public function __construct(
        private readonly Supplier $supplier,
        private readonly Collection $rows,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
    ) {}

    public function title(): string
    {
        // Назви аркушів в Excel обмежені 31 символом і не можуть містити / \ ? * [ ].
        $title = preg_replace('/[\/\\\\?*\[\]]/', ' ', $this->supplier->name);

        return mb_substr($title, 0, 31);
    }

    public function headings(): array
    {
        return [
            '№',
            "Прізвище, ім'я",
            'Клас',
            ...$this->dates()->map(fn (CarbonImmutable $date): string => $date->format('d.m.Y'))->all(),
            'Разом',
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

        return [
            '',
            'Разом',
            '',
            ...$dateTotals,
            $this->rows->sum('total'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = $sheet->getHighestColumn();
        $lastRow = $sheet->getHighestRow();

        $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4'],
            ],
        ]);
        $sheet->freezePane('A2');

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
