<?php

namespace Tests\Feature;

use App\Exports\OrdersByPeriodExport;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersByPeriodExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_last_row_totals_every_column(): void
    {
        $rows = collect([
            ['number' => 1, 'full_name' => 'Іваненко Марія', 'class' => '5-А', 'by_date' => ['2026-09-01' => 275.0, '2026-09-02' => 0.0], 'by_supplier' => [], 'total' => 275.0],
            ['number' => 2, 'full_name' => 'Петренко Іван', 'class' => '7-Б', 'by_date' => ['2026-09-01' => 100.0, '2026-09-02' => 50.0], 'by_supplier' => [], 'total' => 150.0],
        ]);

        $export = new OrdersByPeriodExport($rows, collect(), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

        $data = $export->array();

        $this->assertCount(3, $data); // 2 людини + рядок «Разом»

        $totalRow = $data[2];

        $this->assertSame('Разом', $totalRow[1]);
        $this->assertSame(375.0, $totalRow[3]); // сума за 2026-09-01: 275 + 100
        $this->assertSame(50.0, $totalRow[4]);  // сума за 2026-09-02: 0 + 50
        $this->assertSame(425.0, $totalRow[5]); // загальна сума: 275 + 150
    }

    public function test_supplier_columns_come_before_the_total_column(): void
    {
        $smachno = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $domashnya = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        $rows = collect([
            [
                'number' => 1,
                'full_name' => 'Іваненко Марія',
                'class' => '5-А',
                'by_date' => ['2026-09-01' => 330.0],
                'by_supplier' => [$smachno->id => 230.0, $domashnya->id => 100.0],
                'total' => 330.0,
            ],
        ]);

        $export = new OrdersByPeriodExport($rows, collect([$smachno, $domashnya]), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

        // № / Прізвище / Клас / 01.09 / Смачно / Домашня кухня / Всього до сплати
        $this->assertSame(
            ['№', "Прізвище, ім'я", 'Клас', '01.09.2026', 'Смачно', 'Домашня кухня', 'Всього до сплати'],
            $export->headings(),
        );

        $row = $export->array()[0];

        $this->assertSame(230.0, $row[4]);
        $this->assertSame(100.0, $row[5]);
        $this->assertSame(330.0, $row[6]);

        $totalRow = $export->array()[1];
        $this->assertSame(230.0, $totalRow[4]);
        $this->assertSame(100.0, $totalRow[5]);
        $this->assertSame(330.0, $totalRow[6]);
    }

    public function test_header_row_is_styled_and_frozen(): void
    {
        $rows = collect([
            ['number' => 1, 'full_name' => 'Іваненко Марія', 'class' => '5-А', 'by_date' => ['2026-09-01' => 100.0], 'by_supplier' => [], 'total' => 100.0],
        ]);

        $export = new OrdersByPeriodExport($rows, collect(), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

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
