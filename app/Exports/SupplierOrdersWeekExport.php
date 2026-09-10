<?php

namespace App\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Тижневий звіт постачальника «хто що замовив».
 *
 * Два рядки шапки: 1-й — день тижня + дата (об'єднані комірки над
 * секціями цього дня), 2-й — назва секції з ціною (і складом комплексу).
 * Перші три колонки (№ / Прізвище, ім'я / Клас) об'єднані по вертикалі.
 * Дані — з 3-го рядка: у клітинці назва замовленої страви або порожньо.
 */
class SupplierOrdersWeekExport implements Export, FromArray, WithColumnWidths, WithStyles
{
    use Exportable;

    private const FIXED_COLUMNS = 3;

    /**
     * @param  Collection<int, array{key: string, date: CarbonImmutable, section_title: string, price: float|null, is_complex: bool, complex_dishes: string|null}>  $columns
     * @param  Collection<int, array{full_name: string, class: string, cells: array<string, string>}>  $rows
     */
    public function __construct(
        private readonly Collection $columns,
        private readonly Collection $rows,
    ) {}

    public function array(): array
    {
        return [
            $this->dayHeaderRow(),
            $this->sectionHeaderRow(),
            ...$this->dataRows(),
        ];
    }

    /** @return array<int, string> */
    private function dayHeaderRow(): array
    {
        $row = ['№', "Прізвище, ім'я", 'Клас'];

        foreach ($this->columns as $column) {
            $row[] = $column['date']->translatedFormat('l, d.m.Y');
        }

        return $row;
    }

    /** @return array<int, string> */
    private function sectionHeaderRow(): array
    {
        $row = ['', '', ''];

        foreach ($this->columns as $column) {
            $title = $column['section_title'];

            if ($column['price'] !== null) {
                $title .= ' ('.rtrim(rtrim(number_format($column['price'], 2, ',', ' '), '0'), ',').' грн)';
            }

            if ($column['is_complex'] && $column['complex_dishes'] !== null) {
                $title .= ":\n".$column['complex_dishes'];
            }

            $row[] = $title;
        }

        return $row;
    }

    /** @return array<int, array<int, string|int>> */
    private function dataRows(): array
    {
        return $this->rows
            ->values()
            ->map(function (array $row, int $index): array {
                $cells = [$index + 1, $row['full_name'], $row['class']];

                foreach ($this->columns as $column) {
                    $cells[] = $row['cells'][$column['key']] ?? '';
                }

                return $cells;
            })
            ->all();
    }

    public function columnWidths(): array
    {
        $widths = [
            'A' => 5,
            'B' => 28,
            'C' => 14,
        ];

        for ($i = 0; $i < $this->columns->count(); $i++) {
            $widths[Coordinate::stringFromColumnIndex(self::FIXED_COLUMNS + 1 + $i)] = 20;
        }

        return $widths;
    }

    public function styles(Worksheet $sheet): array
    {
        $totalColumns = self::FIXED_COLUMNS + $this->columns->count();
        $lastColumn = Coordinate::stringFromColumnIndex($totalColumns);
        $lastRow = 2 + $this->rows->count();

        // Обидва рядки шапки — фіолетовий фон, білий жирний текст, перенос рядків.
        $sheet->getStyle("A1:{$lastColumn}2")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '5B2C87']],
            'alignment' => [
                'wrapText' => true,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        // № / Прізвище / Клас — об'єднати по вертикалі (рядки 1–2).
        foreach (['A', 'B', 'C'] as $col) {
            $sheet->mergeCells("{$col}1:{$col}2");
        }

        // Дні: об'єднати комірки 1-го рядка над усіма секціями того самого дня.
        $columnIndex = self::FIXED_COLUMNS + 1;

        foreach ($this->columns->groupBy(fn (array $c): string => $c['date']->toDateString()) as $dayColumns) {
            $span = $dayColumns->count();
            $from = Coordinate::stringFromColumnIndex($columnIndex);
            $to = Coordinate::stringFromColumnIndex($columnIndex + $span - 1);

            if ($span > 1) {
                $sheet->mergeCells("{$from}1:{$to}1");
            }

            $columnIndex += $span;
        }

        // Рядок 1 над секціями (день тижня): темніший фон, текст по центру.
        if ($this->columns->isNotEmpty()) {
            $firstDayColumn = Coordinate::stringFromColumnIndex(self::FIXED_COLUMNS + 1);

            $sheet->getStyle("{$firstDayColumn}1:{$lastColumn}1")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '3B1A5C']],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ]);
        }

        // Закріпити шапку й перші три колонки.
        $sheet->freezePane('D3');

        // Рядок секцій вищий — там може бути склад комплексу.
        $sheet->getRowDimension(2)->setRowHeight(60);

        // Дані: вертикальне центрування, перенос рядків.
        if ($this->rows->isNotEmpty()) {
            $sheet->getStyle("A3:{$lastColumn}{$lastRow}")->applyFromArray([
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
        }

        return [];
    }
}
