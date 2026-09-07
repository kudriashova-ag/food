<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Замовлення учнів чи вчителів за період: № / Прізвище і ім'я / Клас /
 * одна колонка на кожен день періоду (сума за день) / Всього до сплати.
 */
class OrdersByPeriodExport implements Export, FromArray, ShouldAutoSize, WithHeadings
{
    use Exportable;

    /** @param Collection<int, array{number: int, full_name: string, class: string, by_date: array<string, float>, total: float}> $rows */
    public function __construct(
        private readonly Collection $rows,
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
            'Всього до сплати',
        ];
    }

    public function array(): array
    {
        return $this->rows
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
