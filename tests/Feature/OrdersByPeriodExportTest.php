<?php

namespace Tests\Feature;

use App\Exports\OrdersByPeriodExport;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class OrdersByPeriodExportTest extends TestCase
{
    public function test_the_last_row_totals_every_column(): void
    {
        $rows = collect([
            ['number' => 1, 'full_name' => 'Іваненко Марія', 'class' => '5-А', 'by_date' => ['2026-09-01' => 275.0, '2026-09-02' => 0.0], 'total' => 275.0],
            ['number' => 2, 'full_name' => 'Петренко Іван', 'class' => '7-Б', 'by_date' => ['2026-09-01' => 100.0, '2026-09-02' => 50.0], 'total' => 150.0],
        ]);

        $export = new OrdersByPeriodExport($rows, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

        $data = $export->array();

        $this->assertCount(3, $data); // 2 людини + рядок «Разом»

        $totalRow = $data[2];

        $this->assertSame('Разом', $totalRow[1]);
        $this->assertSame(375.0, $totalRow[3]); // сума за 2026-09-01: 275 + 100
        $this->assertSame(50.0, $totalRow[4]);  // сума за 2026-09-02: 0 + 50
        $this->assertSame(425.0, $totalRow[5]); // загальна сума: 275 + 150
    }

    public function test_header_row_is_styled_and_frozen(): void
    {
        $rows = collect([
            ['number' => 1, 'full_name' => 'Іваненко Марія', 'class' => '5-А', 'by_date' => ['2026-09-01' => 100.0], 'total' => 100.0],
        ]);

        $export = new OrdersByPeriodExport($rows, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($export->headings());
        $sheet->fromArray($export->array(), null, 'A2');

        $export->styles($sheet);

        $this->assertSame('solid', $sheet->getStyle('A1')->getFill()->getFillType());
        $this->assertSame('4472C4', $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('A2', $sheet->getFreezePane());
    }
}
