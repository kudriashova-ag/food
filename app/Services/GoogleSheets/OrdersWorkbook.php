<?php

namespace App\Services\GoogleSheets;

use App\Exports\SupplierOrdersWeekExport;
use App\Models\Supplier;
use App\Services\Reports\SupplierOrdersWeekService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Google Таблиця замовлень: вкладка на кожну пару «постачальник + тиждень»
 * (той самий звіт, що «Замовлення за тиждень» в Excel) і «Журнал змін».
 *
 * Вкладки поточного тижня ставимо першими зліва: змусити Google відкривати
 * певну вкладку через API не можна — він пам'ятає останню переглянуту кожним.
 */
class OrdersWorkbook
{
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

    public function __construct(
        private readonly GoogleSheetsClient $client,
        private readonly SupplierOrdersWeekService $reports,
    ) {}

    /** «Смачно 27.10–31.10.2026»: з роком, щоб упорядковувати тижні різних років. */
    public static function weekTitle(Supplier $supplier, CarbonInterface $monday): string
    {
        // Символи, яких Google не допускає в назві вкладки, і зайві пробіли.
        $name = trim(preg_replace('~[\[\]*?/\\\\:]+~u', ' ', $supplier->name) ?? $supplier->name);

        $monday = static::weekStart($monday);

        return sprintf('%s %s–%s', $name, $monday->format('d.m'), $monday->addDays(4)->format('d.m.Y'));
    }

    /** Понеділок тижня, до якого належить дата харчування. */
    public static function weekStart(CarbonInterface|string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date)->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }

    /** Перебудовує вкладку тижня постачальника за актуальними даними. */
    public function writeWeek(Supplier $supplier, CarbonInterface $monday): void
    {
        $monday = static::weekStart($monday);
        $report = $this->reports->build($supplier, $monday, $monday->addDays(6));
        $title = static::weekTitle($supplier, $monday);

        $existing = collect($this->client->sheets())->firstWhere('title', $title);

        // Ні меню, ні замовлень, а вкладки ще немає — нема чого й створювати.
        if ($report['columns']->isEmpty() && $existing === null) {
            return;
        }

        $sheetId = $existing['sheetId'] ?? $this->addSheet($title);

        $values = $report['columns']->isEmpty()
            ? [['На цей тиждень немає ні меню, ні замовлень.']]
            : (new SupplierOrdersWeekExport($report['columns'], $report['rows']))->array();

        $columnCount = max(1, ...array_map('count', $values));

        // Спершу прибираємо старий вміст і оформлення: кількість колонок і рядків
        // між оновленнями змінюється, а об'єднані клітинки заважали б записові.
        $this->client->batchUpdate([
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

        $this->client->clearValues($title);
        $this->client->updateValues($title, $values);

        if ($report['columns']->isNotEmpty()) {
            $this->client->batchUpdate($this->weekFormatting($sheetId, $report['columns'], count($values)));
        }

        $this->reorderTabs();
    }

    /**
     * Дописує рядки в «Журнал змін», створюючи вкладку з шапкою за потреби.
     *
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    public function appendJournal(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        if (collect($this->client->sheets())->firstWhere('title', self::JOURNAL_TITLE) === null) {
            $sheetId = $this->addSheet(self::JOURNAL_TITLE);

            $this->client->updateValues(self::JOURNAL_TITLE, [self::JOURNAL_HEADER]);
            $this->client->batchUpdate([
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

            $this->reorderTabs();
        }

        $this->client->appendValues(self::JOURNAL_TITLE, $rows);
    }

    /**
     * Порядок вкладок: поточний тиждень → «Журнал змін» → інші тижні від новіших
     * до старіших → решта (наприклад, порожній «Аркуш1» нової таблиці).
     */
    public function reorderTabs(): void
    {
        $currentMonday = static::weekStart(today());

        $sheets = collect($this->client->sheets())
            ->map(fn (array $sheet): array => $sheet + ['monday' => $this->mondayFromTitle($sheet['title'])]);

        $current = $sheets->filter(fn (array $s): bool => $s['monday']?->equalTo($currentMonday) ?? false)->sortBy('title');
        $journal = $sheets->where('title', self::JOURNAL_TITLE);
        $weeks = $sheets
            ->filter(fn (array $s): bool => $s['monday'] !== null && ! $s['monday']->equalTo($currentMonday))
            ->sortBy([
                fn (array $a, array $b): int => $b['monday'] <=> $a['monday'],
                fn (array $a, array $b): int => strcmp($a['title'], $b['title']),
            ]);
        $rest = $sheets
            ->filter(fn (array $s): bool => $s['monday'] === null && $s['title'] !== self::JOURNAL_TITLE)
            ->sortBy('index');

        $desired = $current->concat($journal)->concat($weeks)->concat($rest)->values();

        if ($desired->pluck('sheetId')->all() === $sheets->sortBy('index')->pluck('sheetId')->values()->all()) {
            return;
        }

        // Ставимо вкладки на місця зліва направо: кожна рухається лише ліворуч,
        // тож позиції вже розставлених не збиваються (у Google переміщення
        // праворуч рахує індекс інакше).
        $this->client->batchUpdate($desired
            ->map(fn (array $sheet, int $position): array => ['updateSheetProperties' => [
                'properties' => ['sheetId' => $sheet['sheetId'], 'index' => $position],
                'fields' => 'index',
            ]])
            ->all());
    }

    /** Понеділок тижня з назви «… 27.10–31.10.2026»; не тижнева вкладка — null. */
    private function mondayFromTitle(string $title): ?CarbonImmutable
    {
        if (! preg_match('~(\d{2})\.(\d{2})–(\d{2})\.(\d{2})\.(\d{4})$~u', $title, $m)) {
            return null;
        }

        [$day, $month, $fridayMonth, $year] = [(int) $m[1], (int) $m[2], (int) $m[4], (int) $m[5]];

        // Тиждень на межі років: понеділок у грудні, п'ятниця вже в січні.
        $mondayYear = $month > $fridayMonth ? $year - 1 : $year;

        return checkdate($month, $day, $mondayYear)
            ? CarbonImmutable::create($mondayYear, $month, $day)->startOfDay()
            : null;
    }

    private function addSheet(string $title): int
    {
        $reply = $this->client->batchUpdate([
            ['addSheet' => ['properties' => ['title' => $title, 'index' => 0]]],
        ]);

        return (int) data_get($reply, 'replies.0.addSheet.properties.sheetId');
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
            // Тонка сітка по всій таблиці.
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
                $span = $columns->slice($position)
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
