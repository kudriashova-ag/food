<?php

namespace App\Services\GoogleSheets;

use App\Exports\SupplierOrdersWeekExport;
use App\Models\Student;
use App\Models\Supplier;
use App\Services\Reports\SupplierOrdersWeekService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Файл тижня постачальника на Google Диску (див. DriveLayout):
 *   «Замовлення»  — той самий звіт, що «Замовлення за тиждень» в Excel,
 *                   скасовані страви лишаються в клітинках на червоному тлі;
 *   «Оплата»      — до сплати за днями й за тиждень, «Борг» заповнюють люди;
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

    public const PAYMENT_TITLE = 'Оплата';

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

    // «Оплата»: шапка, «Всього до сплати», «Борг».
    private const PAYMENT_HEADER_COLOR = 'D9D9D9';

    private const PAYMENT_TOTAL_COLOR = 'FFFF00';

    private const PAYMENT_DEBT_COLOR = '674EA7';

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
        $userColors = $this->userColors($this->client->gridColors($spreadsheetId, self::ORDERS_TITLE));

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
                ...$this->restoreColors($sheetId, [...self::FIXED_KEYS, ...$report['columns']->pluck('key')->all()], self::HEADER_ROWS, $rows, $userColors),
            ]);
        }

        $this->writePayment($spreadsheetId, $report['columns'], $rows);
    }

    /**
     * Вкладка «Оплата»: сума за кожен день і всього до сплати — з замовлень,
     * «Борг» і кольори клітинок — від людей, тож їх зберігаємо за учнем.
     * Учень, у якого замовлень уже немає, але записано борг, лишається в таблиці.
     *
     * @param  Collection<int, array<string, mixed>>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows  уже впорядковані sheetOrder()
     */
    private function writePayment(string $spreadsheetId, Collection $columns, Collection $rows): void
    {
        $sheetId = $this->paymentSheet($spreadsheetId);

        $grid = $this->client->gridColors($spreadsheetId, self::PAYMENT_TITLE);
        $userColors = $this->userColors($grid);
        $debts = $this->debts($grid);

        $rows = $this->withDebtors($rows, $debts);
        $dates = $columns->map(fn (array $column): string => $column['date']->toDateString())->unique()->values();

        $keys = [...self::FIXED_KEYS, ...$dates->map(fn (string $date): string => 'day:'.$date)->all(), '_total', '_debt'];

        $values = [
            ['', ...$keys],
            ['', '№', "Прізвище, ім'я", 'Клас', ...$dates->map(fn (string $date): string => CarbonImmutable::parse($date)->format('d.m.Y'))->all(), 'Всього до сплати', 'Борг'],
        ];

        foreach ($rows as $position => $row) {
            $amounts = $dates->map(fn (string $date): float|int => $this->number($row['amounts'][$date] ?? 0))->all();

            $values[] = [
                static::rowKey($row),
                $position + 1,
                $row['full_name'],
                $row['class'],
                ...$amounts,
                $this->number(array_sum($amounts)),
                $debts[static::rowKey($row)] ?? '',
            ];
        }

        $this->client->batchUpdate($spreadsheetId, [
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
                        'columnCount' => max(26, count($keys) + self::COL_OFFSET),
                        'frozenRowCount' => self::ROW_OFFSET + 1,
                        'frozenColumnCount' => self::COL_OFFSET + self::FIXED_COLUMNS,
                    ],
                ],
                'fields' => 'gridProperties(rowCount,columnCount,frozenRowCount,frozenColumnCount)',
            ]],
        ]);

        $this->client->clearValues($spreadsheetId, self::PAYMENT_TITLE);
        $this->client->updateValues($spreadsheetId, self::PAYMENT_TITLE, $values);

        $this->client->batchUpdate($spreadsheetId, [
            ...$this->paymentFormatting($sheetId, count($keys), count($values)),
            ...$this->restoreColors($sheetId, $keys, 1, $rows, $userColors),
        ]);
    }

    /**
     * Записаний людьми «Борг»: [ключ учня] => значення як є (число чи текст).
     *
     * @param  array<int, array<int, array<string, mixed>>>  $grid
     * @return array<string, string|int|float|bool>
     */
    private function debts(array $grid): array
    {
        $column = collect($grid[0] ?? [])->search(fn (array $cell): bool => $cell['value'] === '_debt');

        if ($column === false) {
            return [];
        }

        $debts = [];

        foreach ($this->studentCells($grid) as $rowKey => $cells) {
            $debt = $cells[$column]['entered'] ?? null;

            if ($debt !== null && $debt !== '') {
                $debts[$rowKey] = $debt;
            }
        }

        return $debts;
    }

    /**
     * Додає до рядків тих, хто має записаний борг, але цього тижня вже нічого не замовляє,
     * і впорядковує все знову.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $debts
     * @return Collection<int, array<string, mixed>>
     */
    private function withDebtors(Collection $rows, array $debts): Collection
    {
        $present = $rows->map(fn (array $row): string => static::rowKey($row))->flip();
        $missing = collect(array_keys($debts))
            ->reject(fn (string $key): bool => $present->has($key))
            ->map(fn (string $key): int => (int) substr($key, 1));

        if ($missing->isEmpty()) {
            return $rows;
        }

        $debtors = Student::query()
            ->with('schoolClass')
            ->whereIn('id', $missing)
            ->get()
            ->map(fn (Student $student): array => [...$this->reports->studentColumns($student), 'amounts' => []]);

        return $this->sheetOrder($rows->concat($debtors));
    }

    /** 230.0 → 230: ціле число без «,00» у таблиці. */
    private function number(float|int $value): float|int
    {
        return floor($value) == $value ? (int) $value : round($value, 2);
    }

    /** ID вкладки «Оплата» — одразу після «Замовлення». */
    private function paymentSheet(string $spreadsheetId): int
    {
        $payment = collect($this->client->sheets($spreadsheetId))->firstWhere('title', self::PAYMENT_TITLE);

        return $payment['sheetId'] ?? $this->addSheet($spreadsheetId, self::PAYMENT_TITLE, index: 1);
    }

    /**
     * Оформлення «Оплати»: сіра шапка, жовта «Всього до сплати», фіолетовий «Борг», сітка.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentFormatting(int $sheetId, int $columnCount, int $rowCount): array
    {
        $top = self::ROW_OFFSET;
        $left = self::COL_OFFSET;
        $right = $left + $columnCount;
        $total = $right - 2;
        $debt = $right - 1;

        $range = fn (int $startRow, int $endRow, int $startCol, int $endCol): array => [
            'sheetId' => $sheetId,
            'startRowIndex' => $startRow,
            'endRowIndex' => $endRow,
            'startColumnIndex' => $startCol,
            'endColumnIndex' => $endCol,
        ];

        $background = fn (int $startCol, int $endCol, string $color, string $text = '000000'): array => ['repeatCell' => [
            'range' => $range($top, $top + 1, $startCol, $endCol),
            'cell' => ['userEnteredFormat' => [
                'backgroundColor' => $this->color($color),
                'textFormat' => ['bold' => true, 'foregroundColor' => $this->color($text)],
            ]],
            'fields' => 'userEnteredFormat(backgroundColor,textFormat)',
        ]];

        $line = ['style' => 'SOLID', 'color' => $this->color('000000')];

        return [
            $this->hideDimension($sheetId, 'ROWS', 0, $top),
            $this->hideDimension($sheetId, 'COLUMNS', 0, $left),
            ['repeatCell' => [
                'range' => $range($top, $rowCount, $left, $right),
                'cell' => ['userEnteredFormat' => ['wrapStrategy' => 'WRAP', 'verticalAlignment' => 'MIDDLE']],
                'fields' => 'userEnteredFormat(wrapStrategy,verticalAlignment)',
            ]],
            ['repeatCell' => [
                'range' => $range($top, $top + 1, $left, $right),
                'cell' => ['userEnteredFormat' => ['horizontalAlignment' => 'CENTER']],
                'fields' => 'userEnteredFormat.horizontalAlignment',
            ]],
            $background($left, $total, self::PAYMENT_HEADER_COLOR),
            $background($total, $debt, self::PAYMENT_TOTAL_COLOR),
            $background($debt, $right, self::PAYMENT_DEBT_COLOR, 'FFFFFF'),
            ['updateBorders' => [
                'range' => $range($top, $rowCount, $left, $right),
                'top' => $line, 'bottom' => $line, 'left' => $line, 'right' => $line,
                'innerHorizontal' => $line, 'innerVertical' => $line,
            ]],
            $this->columnWidth($sheetId, $left, $left + 1, 40),
            $this->columnWidth($sheetId, $left + 1, $left + 2, 220),
            $this->columnWidth($sheetId, $left + 2, $left + 3, 70),
            $this->columnWidth($sheetId, $left + 3, $total, 110),
            $this->columnWidth($sheetId, $total, $debt, 110),
            $this->columnWidth($sheetId, $debt, $right, 180),
        ];
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
            // Вкладка «Замовлення» має бути першою — тож журнал додаємо в кінець, після неї й «Оплати».
            $this->ordersSheet($spreadsheetId);

            $sheetId = $this->addSheet($spreadsheetId, self::JOURNAL_TITLE);

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
     * Рядки учнів із прочитаної вкладки, за ключем учня з прихованої колонки A
     * (шапку й порожні рядки пропускаємо).
     *
     * @param  array<int, array<int, array<string, mixed>>>  $grid
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function studentCells(array $grid): array
    {
        $rows = [];

        foreach (array_slice($grid, self::ROW_OFFSET) as $cells) {
            $rowKey = $cells[0]['value'] ?? '';

            if (preg_match('~^s\d+$~', $rowKey)) {
                $rows[$rowKey] = $cells;
            }
        }

        return $rows;
    }

    /**
     * Фон, який людина задала вручну: [ключ учня][ключ колонки] => колір.
     * Білий і червоне тло скасування — оформлення сайту, їх не беремо.
     *
     * @param  array<int, array<int, array<string, mixed>>>  $grid  вміст вкладки з gridColors()
     * @return array<string, array<string, array<string, float>>>
     */
    private function userColors(array $grid): array
    {
        $columnKeys = array_map(fn (array $cell): string => $cell['value'], $grid[0] ?? []);
        $colors = [];

        foreach ($this->studentCells($grid) as $rowKey => $cells) {
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
     * @param  array<int, string>  $columnKeys  ключі колонок вкладки зліва направо
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, array<string, array<string, float>>>  $userColors
     * @return array<int, array<string, mixed>>
     */
    private function restoreColors(int $sheetId, array $columnKeys, int $headerRows, Collection $rows, array $userColors): array
    {
        if ($userColors === []) {
            return [];
        }

        $columnIndex = collect($columnKeys)
            ->flip()
            ->map(fn (int $index): int => self::COL_OFFSET + $index);

        $requests = [];

        foreach ($rows->values() as $position => $row) {
            $rowIndex = self::ROW_OFFSET + $headerRows + $position;

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

        $spare = $sheets->sortBy('index')->first(fn (array $sheet): bool => ! in_array($sheet['title'], [self::PAYMENT_TITLE, self::JOURNAL_TITLE], true));

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

    /** Без index вкладка стає останньою. */
    private function addSheet(string $spreadsheetId, string $title, ?int $index = null): int
    {
        $reply = $this->client->batchUpdate($spreadsheetId, [
            ['addSheet' => ['properties' => array_filter(['title' => $title, 'index' => $index], fn ($value): bool => $value !== null)]],
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
