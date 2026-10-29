<?php

namespace App\Services\GoogleSheets;

use App\Exports\SupplierOrdersWeekExport;
use App\Models\Supplier;
use App\Services\Reports\SupplierOrdersWeekService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Файл тижня постачальника на Google Диску (див. DriveLayout):
 *   «Замовлення»  — той самий звіт, що «Замовлення за тиждень» в Excel,
 *                   скасовані страви лишаються в клітинках на червоному тлі;
 *   «Журнал змін» — рядок на кожне замовлення, скасування, видалення.
 */
class OrdersWorkbook
{
    public const ORDERS_TITLE = 'Замовлення';

    public const JOURNAL_TITLE = 'Журнал змін';

    public const JOURNAL_HEADER = [
        'Час', 'Дія', 'Номер замовлення', 'ПІБ', 'Клас', 'Постачальник', 'Дата харчування',
        'Секція', 'Страва', 'Кількість', 'Сума, грн', 'Хто змінив', 'Причина',
    ];

    private const FIXED_COLUMNS = 3;

    private const HEADER_ROWS = 2;

    // Кольори як у Excel-звіті.
    private const HEADER_COLOR = '5B2C87';

    private const DAY_COLOR = '3B1A5C';

    private const GRID_COLOR = 'BFBFBF';

    /** Тло скасованої страви. */
    private const CANCELLED_COLOR = 'EA9999';

    public function __construct(
        private readonly GoogleSheetsClient $client,
        private readonly SupplierOrdersWeekService $reports,
        private readonly DriveLayout $layout,
    ) {}

    /** «Смачно з 19.10 до 23.10» — назва файлу тижня (понеділок–п'ятниця). */
    public static function fileTitle(Supplier $supplier, CarbonInterface $monday): string
    {
        $monday = static::weekStart($monday);

        return sprintf('%s з %s до %s', DriveLayout::safeName($supplier->name), $monday->format('d.m'), $monday->addDays(4)->format('d.m'));
    }

    /** Понеділок тижня, до якого належить дата харчування. */
    public static function weekStart(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /** Перебудовує вкладку «Замовлення» у файлі тижня за актуальними даними. */
    public function writeWeek(Supplier $supplier, CarbonInterface $monday): void
    {
        $monday = static::weekStart($monday);
        $report = $this->reports->build($supplier, $monday, $monday->addDays(6), withCancelled: true);

        // Ні меню, ні замовлень, а файлу ще немає — нема чого й створювати.
        if ($report['columns']->isEmpty() && ! $this->layout->hasWeekSpreadsheet($supplier, $monday)) {
            return;
        }

        $spreadsheetId = $this->layout->weekSpreadsheet($supplier, $monday);
        $sheetId = $this->ordersSheet($spreadsheetId);

        $values = $report['columns']->isEmpty()
            ? [['На цей тиждень немає ні меню, ні замовлень.']]
            : (new SupplierOrdersWeekExport($report['columns'], $report['rows']))->array();

        $columnCount = max(1, ...array_map('count', $values));

        // Спершу прибираємо старий вміст і оформлення: кількість колонок і рядків
        // між оновленнями змінюється, а об'єднані клітинки заважали б записові.
        $this->client->batchUpdate($spreadsheetId, [
            ['unmergeCells' => ['range' => ['sheetId' => $sheetId]]],
            ['repeatCell' => [
                'range' => ['sheetId' => $sheetId],
                'cell' => ['userEnteredFormat' => (object) []],
                'fields' => 'userEnteredFormat',
            ]],
            ['updateSheetProperties' => [
                'properties' => [
                    'sheetId' => $sheetId,
                    'gridProperties' => [
                        'rowCount' => max(100, count($values) + 20),
                        'columnCount' => max(26, $columnCount),
                        'frozenRowCount' => $report['columns']->isEmpty() ? 0 : self::HEADER_ROWS,
                        'frozenColumnCount' => $report['columns']->isEmpty() ? 0 : self::FIXED_COLUMNS,
                    ],
                ],
                'fields' => 'gridProperties(rowCount,columnCount,frozenRowCount,frozenColumnCount)',
            ]],
        ]);

        $this->client->clearValues($spreadsheetId, self::ORDERS_TITLE);
        $this->client->updateValues($spreadsheetId, self::ORDERS_TITLE, $values);

        if ($report['columns']->isNotEmpty()) {
            $this->client->batchUpdate($spreadsheetId, [
                ...$this->weekFormatting($sheetId, $report['columns'], count($values)),
                ...$this->cancelledCells($sheetId, $report['columns'], $report['rows']),
            ]);
        }
    }

    /**
     * Дописує рядки в «Журнал змін» файлу тижня, створюючи вкладку з шапкою за потреби.
     *
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public function appendJournal(Supplier $supplier, CarbonInterface $monday, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $spreadsheetId = $this->layout->weekSpreadsheet($supplier, static::weekStart($monday));
        $sheets = collect($this->client->sheets($spreadsheetId));

        if ($sheets->firstWhere('title', self::JOURNAL_TITLE) === null) {
            // Вкладка «Замовлення» має бути першою — тож журнал додаємо після неї.
            $this->ordersSheet($spreadsheetId);

            $sheetId = $this->addSheet($spreadsheetId, self::JOURNAL_TITLE, index: 1);

            $this->client->updateValues($spreadsheetId, self::JOURNAL_TITLE, [self::JOURNAL_HEADER]);
            $this->client->batchUpdate($spreadsheetId, [
                ['updateSheetProperties' => [
                    'properties' => ['sheetId' => $sheetId, 'gridProperties' => ['frozenRowCount' => 1]],
                    'fields' => 'gridProperties.frozenRowCount',
                ]],
                ['repeatCell' => [
                    'range' => ['sheetId' => $sheetId, 'startRowIndex' => 0, 'endRowIndex' => 1],
                    'cell' => ['userEnteredFormat' => [
                        'textFormat' => ['bold' => true, 'foregroundColor' => $this->color('FFFFFF')],
                        'backgroundColor' => $this->color(self::HEADER_COLOR),
                    ]],
                    'fields' => 'userEnteredFormat(textFormat,backgroundColor)',
                ]],
            ]);
        }

        $this->client->appendValues($spreadsheetId, self::JOURNAL_TITLE, $rows);
    }

    /**
     * ID вкладки «Замовлення». У щойно створеній таблиці є лише «Аркуш1» —
     * перейменовуємо його, а не додаємо ще одну вкладку.
     */
    private function ordersSheet(string $spreadsheetId): int
    {
        $sheets = collect($this->client->sheets($spreadsheetId));

        $orders = $sheets->firstWhere('title', self::ORDERS_TITLE);

        if ($orders !== null) {
            return $orders['sheetId'];
        }

        $spare = $sheets->sortBy('index')->first(fn (array $sheet): bool => $sheet['title'] !== self::JOURNAL_TITLE);

        if ($spare === null) {
            return $this->addSheet($spreadsheetId, self::ORDERS_TITLE, index: 0);
        }

        $this->client->batchUpdate($spreadsheetId, [
            ['updateSheetProperties' => [
                'properties' => ['sheetId' => $spare['sheetId'], 'title' => self::ORDERS_TITLE, 'index' => 0],
                'fields' => 'title,index',
            ]],
        ]);

        return $spare['sheetId'];
    }

    private function addSheet(string $spreadsheetId, string $title, int $index): int
    {
        $reply = $this->client->batchUpdate($spreadsheetId, [
            ['addSheet' => ['properties' => ['title' => $title, 'index' => $index]]],
        ]);

        return (int) data_get($reply, 'replies.0.addSheet.properties.sheetId');
    }

    /**
     * Червоне тло клітинок зі скасованими стравами.
     *
     * @param  Collection<int, array<string, mixed>>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function cancelledCells(int $sheetId, Collection $columns, Collection $rows): array
    {
        $columnIndex = $columns->values()->pluck('key')->flip();
        $requests = [];

        foreach ($rows->values() as $position => $row) {
            foreach (array_keys($row['cancelled'] ?? []) as $key) {
                if (! $columnIndex->has($key)) {
                    continue;
                }

                $column = self::FIXED_COLUMNS + $columnIndex[$key];
                $rowIndex = self::HEADER_ROWS + $position;

                $requests[] = ['repeatCell' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'startRowIndex' => $rowIndex,
                        'endRowIndex' => $rowIndex + 1,
                        'startColumnIndex' => $column,
                        'endColumnIndex' => $column + 1,
                    ],
                    'cell' => ['userEnteredFormat' => ['backgroundColor' => $this->color(self::CANCELLED_COLOR)]],
                    'fields' => 'userEnteredFormat.backgroundColor',
                ]];
            }
        }

        return $requests;
    }

    /**
     * Оформлення як у SupplierOrdersWeekExport: шапка з заливкою, об'єднані дні,
     * тонка сітка й товсті рамки між днями.
     *
     * @param  Collection<int, array<string, mixed>>  $columns
     * @return array<int, array<string, mixed>>
     */
    private function weekFormatting(int $sheetId, Collection $columns, int $rowCount): array
    {
        $totalColumns = self::FIXED_COLUMNS + $columns->count();
        $range = fn (int $startRow, int $endRow, int $startCol, int $endCol): array => [
            'sheetId' => $sheetId,
            'startRowIndex' => $startRow,
            'endRowIndex' => $endRow,
            'startColumnIndex' => $startCol,
            'endColumnIndex' => $endCol,
        ];

        $thin = ['style' => 'SOLID', 'color' => $this->color(self::GRID_COLOR)];
        $thick = ['style' => 'SOLID_THICK', 'color' => $this->color(self::DAY_COLOR)];

        $requests = [
            // Шапка: обидва рядки.
            ['repeatCell' => [
                'range' => $range(0, self::HEADER_ROWS, 0, $totalColumns),
                'cell' => ['userEnteredFormat' => [
                    'backgroundColor' => $this->color(self::HEADER_COLOR),
                    'textFormat' => ['bold' => true, 'foregroundColor' => $this->color('FFFFFF')],
                    'wrapStrategy' => 'WRAP',
                    'verticalAlignment' => 'MIDDLE',
                ]],
                'fields' => 'userEnteredFormat(backgroundColor,textFormat,wrapStrategy,verticalAlignment)',
            ]],
            // Рядок днів — темніший і по центру.
            ['repeatCell' => [
                'range' => $range(0, 1, self::FIXED_COLUMNS, $totalColumns),
                'cell' => ['userEnteredFormat' => [
                    'backgroundColor' => $this->color(self::DAY_COLOR),
                    'horizontalAlignment' => 'CENTER',
                ]],
                'fields' => 'userEnteredFormat(backgroundColor,horizontalAlignment)',
            ]],
            // Дані.
            ['repeatCell' => [
                'range' => $range(self::HEADER_ROWS, $rowCount, 0, $totalColumns),
                'cell' => ['userEnteredFormat' => ['wrapStrategy' => 'WRAP', 'verticalAlignment' => 'MIDDLE']],
                'fields' => 'userEnteredFormat(wrapStrategy,verticalAlignment)',
            ]],
            // Тонка сітка по всій таблиці, товста — праворуч від останнього дня.
            ['updateBorders' => [
                'range' => $range(0, $rowCount, 0, $totalColumns),
                'top' => $thin, 'bottom' => $thin, 'left' => $thin, 'right' => $thick,
                'innerHorizontal' => $thin, 'innerVertical' => $thin,
            ]],
            // Ширина колонок: № / ПІБ / Клас / страви.
            $this->columnWidth($sheetId, 0, 1, 40),
            $this->columnWidth($sheetId, 1, 2, 220),
            $this->columnWidth($sheetId, 2, 3, 110),
            $this->columnWidth($sheetId, self::FIXED_COLUMNS, $totalColumns, 160),
            // Рядок секцій вищий — там склад комплексу.
            ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => 1, 'endIndex' => 2],
                'properties' => ['pixelSize' => 110],
                'fields' => 'pixelSize',
            ]],
        ];

        // № / Прізвище / Клас — об'єднані по вертикалі.
        foreach (range(0, self::FIXED_COLUMNS - 1) as $column) {
            $requests[] = ['mergeCells' => ['range' => $range(0, self::HEADER_ROWS, $column, $column + 1), 'mergeType' => 'MERGE_ALL']];
        }

        $columnIndex = self::FIXED_COLUMNS;

        foreach ($columns->values() as $position => $column) {
            if ($column['first_of_day'] ?? false) {
                $span = $columns->values()->slice($position)
                    ->takeWhile(fn (array $c): bool => $c['date']->equalTo($column['date']))
                    ->count();

                if ($span > 1) {
                    $requests[] = ['mergeCells' => ['range' => $range(0, 1, $columnIndex, $columnIndex + $span), 'mergeType' => 'MERGE_ALL']];
                }

                // Товста рамка ліворуч від першої колонки дня.
                $requests[] = ['updateBorders' => ['range' => $range(0, $rowCount, $columnIndex, $columnIndex + 1), 'left' => $thick]];
            }

            $columnIndex++;
        }

        return $requests;
    }

    /** @return array<string, mixed> */
    private function columnWidth(int $sheetId, int $start, int $end, int $pixels): array
    {
        return ['updateDimensionProperties' => [
            'range' => ['sheetId' => $sheetId, 'dimension' => 'COLUMNS', 'startIndex' => $start, 'endIndex' => $end],
            'properties' => ['pixelSize' => $pixels],
            'fields' => 'pixelSize',
        ]];
    }

    /** @return array{red: float, green: float, blue: float} */
    private function color(string $hex): array
    {
        return [
            'red' => hexdec(substr($hex, 0, 2)) / 255,
            'green' => hexdec(substr($hex, 2, 2)) / 255,
            'blue' => hexdec(substr($hex, 4, 2)) / 255,
        ];
    }
}
