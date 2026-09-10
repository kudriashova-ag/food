<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Exports\SupplierOrdersWeekExport;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

class SupplierOrdersWeekExportTest extends TestCase
{
    public function test_two_header_rows_and_data(): void
    {
        $columns = collect([
            [
                'key' => '2026-09-10|Перше',
                'date' => CarbonImmutable::parse('2026-09-10'),
                'section_title' => 'Перше',
                'section_type' => MenuSectionType::Choice,
                'price' => 45.0,
                'is_complex' => false,
                'complex_dishes' => null,
            ],
            [
                'key' => '2026-09-10|Комплекс №1',
                'date' => CarbonImmutable::parse('2026-09-10'),
                'section_title' => 'Комплекс №1',
                'section_type' => MenuSectionType::Complex,
                'price' => 230.0,
                'is_complex' => true,
                'complex_dishes' => 'Салат, Котлета куряча',
            ],
        ]);

        $rows = collect([
            ['full_name' => 'Іваненко Марія', 'class' => '5-А', 'cells' => ['2026-09-10|Перше' => 'Рибна юшка', '2026-09-10|Комплекс №1' => 'Комплекс']],
            ['full_name' => 'Коваленко Ольга', 'class' => 'Вчитель', 'cells' => ['2026-09-10|Комплекс №1' => 'Комплекс']],
        ]);

        $export = new SupplierOrdersWeekExport($columns, $rows);
        $data = $export->array();

        // 2 рядки шапки + 2 рядки даних
        $this->assertCount(4, $data);

        // 1-й рядок шапки: № / Прізвище / Клас / день / день
        $this->assertSame(['№', "Прізвище, ім'я", 'Клас'], array_slice($data[0], 0, 3));
        $this->assertStringContainsString('10.09.2026', $data[0][3]);
        $this->assertStringContainsString('10.09.2026', $data[0][4]);

        // 2-й рядок шапки: назви секцій із цінами
        $this->assertStringContainsString('Перше (45 грн)', $data[1][3]);
        $this->assertStringContainsString('Комплекс №1 (230 грн)', $data[1][4]);
        $this->assertStringContainsString('Салат, Котлета куряча', $data[1][4]);

        // Дані
        $this->assertSame([1, 'Іваненко Марія', '5-А', 'Рибна юшка', 'Комплекс'], $data[2]);
        $this->assertSame([2, 'Коваленко Ольга', 'Вчитель', '', 'Комплекс'], $data[3]);
    }

    public function test_header_is_styled_and_frozen(): void
    {
        $columns = collect([
            [
                'key' => '2026-09-10|Перше',
                'date' => CarbonImmutable::parse('2026-09-10'),
                'section_title' => 'Перше',
                'section_type' => MenuSectionType::Choice,
                'price' => 45.0,
                'is_complex' => false,
                'complex_dishes' => null,
            ],
        ]);

        $rows = collect([
            ['full_name' => 'Іваненко Марія', 'class' => '5-А', 'cells' => ['2026-09-10|Перше' => 'Борщ']],
        ]);

        $export = new SupplierOrdersWeekExport($columns, $rows);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($export->array());

        $export->styles($sheet);

        $this->assertSame('solid', $sheet->getStyle('A1')->getFill()->getFillType());
        $this->assertSame('5B2C87', $sheet->getStyle('A1')->getFill()->getStartColor()->getRGB());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('D3', $sheet->getFreezePane());

        // № / Прізвище / Клас — об'єднані по вертикалі
        $this->assertContains('A1:A2', array_keys($sheet->getMergeCells()));

        // Рядок дня тижня (D1) — власний темніший фон і центрування
        $this->assertSame('3B1A5C', $sheet->getStyle('D1')->getFill()->getStartColor()->getRGB());
        $this->assertSame('center', $sheet->getStyle('D1')->getAlignment()->getHorizontal());
    }
}
