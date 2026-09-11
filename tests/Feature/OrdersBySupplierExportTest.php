<?php

namespace Tests\Feature;

use App\Exports\OrdersBySupplierExport;
use App\Exports\OrdersBySupplierSheet;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrdersBySupplierExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_sheet_per_supplier_with_the_suppliers_name_as_title(): void
    {
        $smachno = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $domashnya = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        $reports = collect([
            ['supplier' => $smachno, 'rows' => collect([
                ['number' => 1, 'full_name' => 'Іваненко Марія', 'class' => '5-А', 'by_date' => ['2026-09-01' => 230.0], 'total' => 230.0],
            ])],
            ['supplier' => $domashnya, 'rows' => collect([
                ['number' => 1, 'full_name' => 'Петренко Іван', 'class' => '7-Б', 'by_date' => ['2026-09-01' => 100.0], 'total' => 100.0],
            ])],
        ]);

        $export = new OrdersBySupplierExport($reports, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

        $sheets = $export->sheets();

        $this->assertCount(2, $sheets);
        $this->assertSame('Смачно', $sheets[0]->title());
        $this->assertSame('Домашня кухня', $sheets[1]->title());
    }

    public function test_sheet_shows_only_its_own_sums_and_a_total_row(): void
    {
        $supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $rows = collect([
            ['number' => 1, 'full_name' => 'Іваненко Марія', 'class' => '5-А', 'by_date' => ['2026-09-01' => 230.0, '2026-09-02' => 0.0], 'total' => 230.0],
            ['number' => 2, 'full_name' => 'Петренко Іван', 'class' => '7-Б', 'by_date' => ['2026-09-01' => 0.0, '2026-09-02' => 100.0], 'total' => 100.0],
        ]);

        $sheet = new OrdersBySupplierSheet($supplier, $rows, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-02'));

        $this->assertSame(
            ['№', "Прізвище, ім'я", 'Клас', '01.09.2026', '02.09.2026', 'Разом'],
            $sheet->headings(),
        );

        $data = $sheet->array();
        $this->assertCount(3, $data); // 2 людини + «Разом»

        $totalRow = $data[2];
        $this->assertSame('Разом', $totalRow[1]);
        $this->assertSame(230.0, $totalRow[3]);
        $this->assertSame(100.0, $totalRow[4]);
        $this->assertSame(330.0, $totalRow[5]);
    }

    public function test_sheet_title_is_sanitized_and_truncated(): void
    {
        $supplier = Supplier::create([
            'name' => 'Дуже / Довга * Назва [Постачальника] яка? перевищує тридцять один символ',
            'slug' => 'long-name',
        ]);

        $sheet = new OrdersBySupplierSheet($supplier, collect(), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

        $title = $sheet->title();

        $this->assertLessThanOrEqual(31, mb_strlen($title));
        $this->assertStringNotContainsString('/', $title);
        $this->assertStringNotContainsString('*', $title);
        $this->assertStringNotContainsString('[', $title);
    }

    public function test_empty_period_still_produces_a_downloadable_file_with_one_sheet(): void
    {
        $export = new OrdersBySupplierExport(collect(), CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

        $sheets = $export->sheets();

        $this->assertCount(1, $sheets);
        $this->assertSame('Немає замовлень', $sheets[0]->title());
    }

    public function test_header_is_styled_and_frozen(): void
    {
        $supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $rows = collect([
            ['number' => 1, 'full_name' => 'Іваненко Марія', 'class' => '5-А', 'by_date' => ['2026-09-01' => 100.0], 'total' => 100.0],
        ]);

        $sheet = new OrdersBySupplierSheet($supplier, $rows, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01'));

        $spreadsheet = new Spreadsheet();
        $worksheet = $spreadsheet->getActiveSheet();
        $worksheet->fromArray($sheet->headings());
        $worksheet->fromArray($sheet->array(), null, 'A2');

        $sheet->styles($worksheet);

        $this->assertSame('solid', $worksheet->getStyle('A1')->getFill()->getFillType());
        $this->assertSame('4472C4', $worksheet->getStyle('A1')->getFill()->getStartColor()->getRGB());
        $this->assertTrue($worksheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('A2', $worksheet->getFreezePane());
    }
}
