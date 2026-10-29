<?php

namespace App\Services\GoogleSheets;

use App\Jobs\AppendToGoogleSheetJournal;
use App\Jobs\SyncWeekToGoogleSheet;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Передає зміни замовлень на Google Диск: рядки в «Журнал змін» і перебудову
 * вкладки «Замовлення» у файлах зачеплених тижнів (файл на постачальника й тиждень).
 * Усе — фоновими завданнями після коміту, тож оформлення не чекає Google,
 * а збій Google не ламає сайт. Поки Диск не підключено, нічого не відбувається.
 */
class OrderSheetSync
{
    public const PLACED = 'Замовлено';

    public const CANCELLED = 'Скасовано';

    public const DELETED = 'Видалено';

    public static function placed(Order $order): void
    {
        if (! GoogleSheetsClient::isConfigured()) {
            return;
        }

        $order->loadMissing('lines.supplier', 'student.schoolClass');

        static::journal($order->lines, fn (OrderLine $line): array => static::row(
            self::PLACED, $order, $line, $order->student?->full_name,
        ));

        static::syncWeeks(static::weeksOf($order->lines));
    }

    public static function cancelled(OrderLine $line, User $actor, ?string $reason = null): void
    {
        if (! GoogleSheetsClient::isConfigured()) {
            return;
        }

        $line->loadMissing('supplier', 'order.student.schoolClass');

        static::journal(collect([$line]), fn (OrderLine $line): array => static::row(
            self::CANCELLED, $line->order, $line, $actor->name, $reason,
        ));

        static::syncWeeks(static::weeksOf(collect([$line])));
    }

    /**
     * Рядки журналу пишемо до видалення (потім позицій уже немає), а вкладки
     * тижнів оновлюємо після — тому повертаємо замикання, яке викликають після delete().
     */
    public static function deleting(Order $order, ?User $actor): \Closure
    {
        if (! GoogleSheetsClient::isConfigured()) {
            return static fn () => null;
        }

        $order->loadMissing('lines.supplier', 'student.schoolClass');

        static::journal($order->lines, fn (OrderLine $line): array => static::row(
            self::DELETED, $order, $line, $actor?->name,
        ));

        $weeks = static::weeksOf($order->lines);

        return static fn () => static::syncWeeks($weeks);
    }

    /** Те саме для «Видалити всі замовлення»: у журнал кожного тижня — один підсумковий рядок. */
    public static function deletingAll(int $count, ?User $actor): \Closure
    {
        if (! GoogleSheetsClient::isConfigured()) {
            return static fn () => null;
        }

        $weeks = static::weeksOf(OrderLine::query()->get(['supplier_id', 'service_date']));

        foreach ($weeks as [$supplierId, $monday]) {
            AppendToGoogleSheetJournal::dispatch($supplierId, $monday, [[
                now()->format('d.m.Y H:i:s'), self::DELETED, 'Усі замовлення на сайті: '.$count,
                '', '', '', '', '', '', '', '', $actor?->name ?? '', '',
            ]])->afterCommit();
        }

        return static fn () => static::syncWeeks($weeks);
    }

    /**
     * Пари «постачальник + понеділок» без повторів.
     *
     * @param  Collection<int, OrderLine>  $lines
     * @return array<string, array{0: int, 1: string}>
     */
    private static function weeksOf(Collection $lines): array
    {
        return $lines
            ->mapWithKeys(fn (OrderLine $line): array => [static::weekKey($line) => [
                (int) $line->supplier_id,
                OrdersWorkbook::weekStart($line->service_date)->toDateString(),
            ]])
            ->all();
    }

    private static function weekKey(OrderLine $line): string
    {
        return $line->supplier_id.':'.OrdersWorkbook::weekStart($line->service_date)->toDateString();
    }

    /** @param  array<string, array{0: int, 1: string}>  $weeks */
    private static function syncWeeks(array $weeks): void
    {
        foreach ($weeks as [$supplierId, $monday]) {
            SyncWeekToGoogleSheet::dispatch($supplierId, $monday)->afterCommit();
        }
    }

    /**
     * Рядки журналу — у файл свого тижня й постачальника.
     *
     * @param  Collection<int, OrderLine>  $lines
     * @param  callable(OrderLine): array<int, string|int|float>  $row
     */
    private static function journal(Collection $lines, callable $row): void
    {
        foreach ($lines->groupBy(fn (OrderLine $line): string => static::weekKey($line)) as $weekLines) {
            $first = $weekLines->first();

            AppendToGoogleSheetJournal::dispatch(
                (int) $first->supplier_id,
                OrdersWorkbook::weekStart($first->service_date)->toDateString(),
                $weekLines->map($row)->values()->all(),
            )->afterCommit();
        }
    }

    /** @return array<int, string|int|float> */
    private static function row(string $action, Order $order, OrderLine $line, ?string $who, ?string $reason = null): array
    {
        $student = $order->student;

        return [
            now()->format('d.m.Y H:i:s'),
            $action,
            $order->number,
            $student?->full_name ?? '',
            $student === null ? '' : ($student->school_class_id === null ? 'Вчитель' : ($student->schoolClass?->title ?? '')),
            $line->supplier?->name ?? '',
            $line->service_date->format('d.m.Y'),
            $line->section_title ?? '',
            $line->dish_name,
            (int) $line->quantity,
            round((float) $line->quantity * (float) $line->unit_price, 2),
            $who ?? '',
            $reason ?? '',
        ];
    }
}
