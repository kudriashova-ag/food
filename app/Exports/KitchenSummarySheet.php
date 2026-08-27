<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class KitchenSummarySheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    /** @param array{dishes: \Illuminate\Support\Collection, positions: int, students: int} $summary */
    public function __construct(
        private readonly array $summary,
        private readonly CarbonImmutable $date,
    ) {}

    public function title(): string
    {
        return 'Зведення';
    }

    public function headings(): array
    {
        return ['Страва', 'Кількість'];
    }

    public function array(): array
    {
        $rows = $this->summary['dishes']
            ->map(fn (array $dish): array => [$dish['name'], $dish['quantity']])
            ->all();

        $rows[] = ['', ''];
        $rows[] = ['Разом позицій', $this->summary['positions']];
        $rows[] = ['Учнів', $this->summary['students']];

        return $rows;
    }
}
