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
 *
 * «Замовлення» переписується цілком, але фон клітинок, який людина задала
 * вручну, зберігається: прихований рядок 1 містить ключі колонок, прихована
 * колонка A — ключі учнів, тож колір повертається тому самому учневі й колонці,
 * навіть коли рядки зсунулися. Рядки впорядковані: класи → курси коледжу → вчителі.
 */
class OrdersWorkbook
{
    public const ORDERS_TITLE = 'Замовлення';

    public const JOURNAL_TITLE = 'Журнал змін';

    public const JOURNAL_HEADER = [
        'Час', 'Дія', 'Номер замовлення', 'ПІБ', 'Клас', 'Постачальник', 'Дата харчування',
        'Секція', 'Страва', 'Кількість', 'Сума, грн', 'Хто змінив', 'Причина',
    ];

    /** № / Прізвище, ім'я / Клас. */
    private const FIXED_COLUMNS = 3;

    /** Ключі для службових колонок у прихованому рядку 1. */
    private const FIXED_KEYS = ['_num', '_name', '_class'];

    /** Видимі рядки шапки: день і секція. */
    private const HEADER_ROWS = 2;

    /** Прихована колонка A з ключами учнів. */
    private const COL_OFFSET = 1;

    /** Прихований рядок 1 з ключами колонок. */
    private const ROW_OFFSET = 1;

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

        // Кольори, задані вручну, — до того, як вкладку буде переписано.
        $userColors = $this->userColors($spreadsheetId);

        $empty = $report['columns']->isEmpty();
        $rows = $this->sheetOrder($report['rows']);
        $values = $empty
            ? [['На цей тиждень немає ні меню, ні замовлень.']]
            : $this->sheetValues($report['columns'], $rows);

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
                        'frozenRowCount' => $empty ? 0 : self::ROW_OFFSET + self::HEADER_ROWS,
                        'frozenColumnCount' => $empty ? 0 : self::COL_OFFSET + self::FIXED_COLUMNS,
                    ],
                ],
                'fields' => 'gridProperties(rowCount,columnCount,frozenRowCount,frozenColumnCount)',
            ]],
        ]);

        $this->client->clearValues($spreadsheetId, self::ORDERS_TITLE);
        $this->client->updateValues($spreadsheetId, self::ORDERS_TITLE, $values);

        if (! $empty) {
            $this->client->batchUpdate($spreadsheetId, [
                ...$this->weekFormatting($sheetId, $report['columns'], count($values)),
                ...$this->cancelledCells($sheetId, $report['columns'], $rows),
                // Останнім — ручні кольори: вони важливіші за червоне тло скасування.
                ...$this->restoreColors($sheetId, $report['columns'], $rows, $userColors),
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
     * Рядки таблиці: шкільні класи → курси коледжу → вчителі, усередині — за ПІБ.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sheetOrder(Collection $rows): Collection
    {
        // Collator знає український алфавіт (і, ї, є); без intl — звичайне порівняння.
        $collator = class_exists(\Collator::class) ? new \Collator('uk_UA') : null;

        return $rows
            ->sort(function (array $a, array $b) use ($collator): int {
                $byClass = ($a['class_order'] ?? [9]) <=> ($b['class_order'] ?? [9]);

                if ($byClass !== 0) {
                    return $byClass;
                }

                return $collator?->compare($a['full_name'], $b['full_name']) ?? strcmp($a['full_name'], $b['full_name']);
            })
            ->values();
    }

    /**
     * Прихований рядок ключів колонок + шапка й дані з Excel-звіту,
     * до кожного рядка спереду — прихований ключ учня.
     *
     * @param  Collection<int, array<string, mixed>>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<int, mixed>>
     */
    private function sheetValues(Collection $columns, Collection $rows): array
    {
        $export = (new SupplierOrdersWeekExport($columns, $rows))->array();

        $values = [['', ...self::FIXED_KEYS, ...$columns->pluck('key')->all()]];

        foreach ($export as $index => $line) {
            $values[] = [
                $index < self::HEADER_ROWS ? '' : static::rowKey($rows[$index - self::HEADER_ROWS]),
                ...$line,
            ];
        }

        return $values;
    }

    /** @param  array<string, mixed>  $row */
    private static function rowKey(array $row): string
    {
        return 's'.($row['student_id'] ?? 0);
    }

    /**
     * Фон, який людина задала вручну: [ключ учня][ключ колонки] => колір.
     * Білий і червоне тло скасування — оформлення сайту, їх не беремо.
     *
     * @return array<string, array<string, array<string, float>>>
     */
    private function userColors(string $spreadsheetId): array
    {
        $grid = $this->client->gridColors($spreadsheetId, self::ORDERS_TITLE);
        $columnKeys = array_map(fn (array $cell): string => $cell['value'], $grid[0] ?? []);
        $colors = [];

        foreach (array_slice($grid, self::ROW_OFFSET + self::HEADER_ROWS) as $cells) {
            $rowKey = $cells[0]['value'] ?? '';

            if (! preg_match('~^s\d+$~', $rowKey)) {
                continue;
            }

            foreach ($cells as $index => $cell) {
                $columnKey = $columnKeys[$index] ?? '';

                if ($index === 0 || $columnKey === '' || $cell['color'] === null || $this->isOwnColor($cell['color'])) {
                    continue;
                }

                $colors[$rowKey][$columnKey] = $cell['color'];
            }
        }

        return $colors;
    }

    /** @param  array<string, float>  $color */
    private function isOwnColor(array $color): bool
    {
        foreach (['FFFFFF', self::CANCELLED_COLOR] as $hex) {
            $own = $this->color($hex);

            if (abs(($color['red'] ?? 0) - $own['red']) < 0.01
                && abs(($color['green'] ?? 0) - $own['green']) < 0.01
                && abs(($color['blue'] ?? 0) - $own['blue']) < 0.01) {
                return true;
            }
        }

        return false;
    }

    /**
     * Повертає збережені кольори туди, де тепер ті самі учень і колонка.
     *
     * @param  Collection<int, array<string, mixed>>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, array<string, array<string, float>>>  $userColors
     * @return array<int, array<string, mixed>>
     */
    private function restoreColors(int $sheetId, Collection $columns, Collection $rows, array $userColors): array
    {
        if ($userColors === []) {
            return [];
        }

        $columnIndex = collect([...self::FIXED_KEYS, ...$columns->pluck('key')->all()])
            ->flip()
            ->map(fn (int $index): int => self::COL_OFFSET + $index);

        $requests = [];

        foreach ($rows->values() as $position => $row) {
            $rowIndex = self::ROW_OFFSET + self::HEADER_ROWS + $position;

            foreach ($userColors[static::rowKey($row)] ?? [] as $columnKey => $color) {
                if (! $columnIndex->has($columnKey)) {
                    continue;
                }

                $requests[] = $this->backgroundRequest($sheetId, $rowIndex, $columnIndex[$columnKey], $color);
            }
        }

        return $requests;
    }

    /**
     * @param  array<string, float>  $color
     * @return array<string, mixed>
     */
    private function backgroundRequest(int $sheetId, int $row, int $column, array $color): array
    {
        return ['repeatCell' => [
            'range' => [
                'sheetId' => $sheetId,
                'startRowIndex' => $row,
                'endRowIndex' => $row + 1,
                'startColumnIndex' => $column,
                'endColumnIndex' => $column + 1,
            ],
            'cell' => ['userEnteredFormat' => ['backgroundColor' => $color]],
            'fields' => 'userEnteredFormat.backgroundColor',
        ]];
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

                $requests[] = $this->backgroundRequest(
                    $sheetId,
                    self::ROW_OFFSET + self::HEADER_ROWS + $position,
                    self::COL_OFFSET + self::FIXED_COLUMNS + $columnIndex[$key],
                    $this->color(self::CANCELLED_COLOR),
                );
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
        // Таблиця починається після прихованого рядка ключів і прихованої колонки ключів.
        $top = self::ROW_OFFSET;
        $left = self::COL_OFFSET;
        $daysLeft = $left + self::FIXED_COLUMNS;
        $totalColumns = $daysLeft + $columns->count();

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
            // Службові ключі людям не потрібні — ховаємо рядок 1 і колонку A.
            $this->hideDimension($sheetId, 'ROWS', 0, $top),
            $this->hideDimension($sheetId, 'COLUMNS', 0, $left),
            // Шапка: обидва рядки.
            ['repeatCell' => [
                'range' => $range($top, $top + self::HEADER_ROWS, $left, $totalColumns),
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
                'range' => $range($top, $top + 1, $daysLeft, $totalColumns),
                'cell' => ['userEnteredFormat' => [
                    'backgroundColor' => $this->color(self::DAY_COLOR),
                    'horizontalAlignment' => 'CENTER',
                ]],
                'fields' => 'userEnteredFormat(backgroundColor,horizontalAlignment)',
            ]],
            // Дані.
            ['repeatCell' => [
                'range' => $range($top + self::HEADER_ROWS, $rowCount, $left, $totalColumns),
                'cell' => ['userEnteredFormat' => ['wrapStrategy' => 'WRAP', 'verticalAlignment' => 'MIDDLE']],
                'fields' => 'userEnteredFormat(wrapStrategy,verticalAlignment)',
            ]],
            // Тонка сітка по всій таблиці, товста — праворуч від останнього дня.
            ['updateBorders' => [
                'range' => $range($top, $rowCount, $left, $totalColumns),
                'top' => $thin, 'bottom' => $thin, 'left' => $thin, 'right' => $thick,
                'innerHorizontal' => $thin, 'innerVertical' => $thin,
            ]],
            // Ширина колонок: № / ПІБ / Клас / страви.
            $this->columnWidth($sheetId, $left, $left + 1, 40),
            $this->columnWidth($sheetId, $left + 1, $left + 2, 220),
            $this->columnWidth($sheetId, $left + 2, $left + 3, 110),
            $this->columnWidth($sheetId, $daysLeft, $totalColumns, 160),
            // Рядок секцій вищий — там склад комплексу.
            ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sheetId, 'dimension' => 'ROWS', 'startIndex' => $top + 1, 'endIndex' => $top + 2],
                'properties' => ['pixelSize' => 110],
                'fields' => 'pixelSize',
            ]],
        ];

        // № / Прізвище / Клас — об'єднані по вертикалі.
        foreach (range($left, $daysLeft - 1) as $column) {
            $requests[] = ['mergeCells' => ['range' => $range($top, $top + self::HEADER_ROWS, $column, $column + 1), 'mergeType' => 'MERGE_ALL']];
        }

        $columnIndex = $daysLeft;

        foreach ($columns->values() as $position => $column) {
            if ($column['first_of_day'] ?? false) {
                $span = $columns->values()->slice($position)
                    ->takeWhile(fn (array $c): bool => $c['date']->equalTo($column['date']))
                    ->count();

                if ($span > 1) {
                    $requests[] = ['mergeCells' => ['range' => $range($top, $top + 1, $columnIndex, $columnIndex + $span), 'mergeType' => 'MERGE_ALL']];
                }

                // Товста рамка ліворуч від першої колонки дня.
                $requests[] = ['updateBorders' => ['range' => $range($top, $rowCount, $columnIndex, $columnIndex + 1), 'left' => $thick]];
            }

            $columnIndex++;
        }

        return $requests;
    }

    /** @return array<string, mixed> */
    private function hideDimension(int $sheetId, string $dimension, int $start, int $end): array
    {
        return ['updateDimensionProperties' => [
            'range' => ['sheetId' => $sheetId, 'dimension' => $dimension, 'startIndex' => $start, 'endIndex' => $end],
            'properties' => ['hiddenByUser' => true],
            'fields' => 'hiddenByUser',
        ]];
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
