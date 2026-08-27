<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class KitchenHandoutSheet implements FromArray, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        private readonly Collection $classes,
        private readonly CarbonImmutable $date,
    ) {}

    public function title(): string
    {
        return 'Список для видачі';
    }

    public function headings(): array
    {
        return ['Клас', 'Учень', 'Страви'];
    }

    public function array(): array
    {
        $rows = [];

        foreach ($this->classes as $class) {
            foreach ($class['students'] as $student) {
                $rows[] = [$class['class'], $student['name'], $student['dishes']];
            }
        }

        return $rows;
    }
}
