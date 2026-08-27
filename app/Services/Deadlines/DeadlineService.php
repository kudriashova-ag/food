<?php

namespace App\Services\Deadlines;

use App\Exceptions\DeadlinePassedException;
use App\Models\DeadlineOverride;
use App\Models\DeadlineRule;
use App\Models\Supplier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Єдина точка істини по дедлайнах.
 *
 * Порядок вирішення для пари «постачальник + дата»:
 *   1. виняток на конкретну дату (deadline_overrides);
 *   2. відносне правило дня тижня (deadline_rules);
 *   3. нічого не знайдено — приймання закрите.
 *
 * Виняток може задавати лише один із двох дедлайнів — другий тоді береться з правила.
 */
class DeadlineService
{
    /** @var array<int, Collection<int, DeadlineRule>> правила по weekday, ключ — supplier_id */
    private array $rules = [];

    /** @var array<int, Collection<string, DeadlineOverride>> винятки по даті, ключ — supplier_id */
    private array $overrides = [];

    public function for(Supplier|int $supplier, CarbonInterface|string $date): Deadlines
    {
        $supplierId = $supplier instanceof Supplier ? $supplier->id : $supplier;
        $serviceDate = CarbonImmutable::parse($date)->startOfDay();

        $override = $this->overridesFor($supplierId)->get($serviceDate->toDateString());
        $rule = $this->rulesFor($supplierId)->get($serviceDate->isoWeekday());

        $orderAt = $this->resolve(
            $override?->order_deadline_at,
            $rule?->order_offset_days,
            $rule?->order_time,
            $serviceDate,
        );

        $cancelAt = $this->resolve(
            $override?->cancel_deadline_at,
            $rule?->cancel_offset_days,
            $rule?->cancel_time,
            $serviceDate,
        );

        return new Deadlines(
            serviceDate: $serviceDate,
            orderAt: $orderAt,
            cancelAt: $cancelAt,
            fromOverride: $override !== null,
        );
    }

    /**
     * Дедлайни на діапазон дат одним проходом — щоб рендер меню на 14 днів
     * не робив запит на кожен день.
     *
     * @return array<string, Deadlines> ключ — дата у форматі Y-m-d
     */
    public function forRange(Supplier|int $supplier, CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->startOfDay();

        $result = [];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $result[$date->toDateString()] = $this->for($supplier, $date);
        }

        return $result;
    }

    public function canOrder(Supplier|int $supplier, CarbonInterface|string $date, ?CarbonInterface $now = null): bool
    {
        return $this->for($supplier, $date)->orderingOpen($now);
    }

    public function canCancel(Supplier|int $supplier, CarbonInterface|string $date, ?CarbonInterface $now = null): bool
    {
        return $this->for($supplier, $date)->cancellationOpen($now);
    }

    /** Серверна перевірка при кожному додаванні позиції та при оформленні (ТЗ, п. 15.2). */
    public function assertCanOrder(Supplier|int $supplier, CarbonInterface|string $date, ?CarbonInterface $now = null): void
    {
        $deadlines = $this->for($supplier, $date);

        if (! $deadlines->orderingOpen($now)) {
            throw DeadlinePassedException::ordering($deadlines->serviceDate);
        }
    }

    public function assertCanCancel(Supplier|int $supplier, CarbonInterface|string $date, ?CarbonInterface $now = null): void
    {
        $deadlines = $this->for($supplier, $date);

        if (! $deadlines->cancellationOpen($now)) {
            throw DeadlinePassedException::cancellation($deadlines->serviceDate);
        }
    }

    /** Скидає кеш — потрібно після зміни правил чи винятків у межах одного запиту. */
    public function forget(Supplier|int|null $supplier = null): void
    {
        if ($supplier === null) {
            $this->rules = [];
            $this->overrides = [];

            return;
        }

        $supplierId = $supplier instanceof Supplier ? $supplier->id : $supplier;

        unset($this->rules[$supplierId], $this->overrides[$supplierId]);
    }

    private function resolve(
        ?CarbonInterface $overrideValue,
        ?int $offsetDays,
        ?string $time,
        CarbonImmutable $serviceDate,
    ): ?CarbonImmutable {
        if ($overrideValue !== null) {
            return CarbonImmutable::parse($overrideValue);
        }

        if ($offsetDays === null || $time === null) {
            return null;
        }

        return $serviceDate->subDays($offsetDays)->setTimeFromTimeString($time);
    }

    /** @return Collection<int, DeadlineRule> */
    private function rulesFor(int $supplierId): Collection
    {
        return $this->rules[$supplierId] ??= DeadlineRule::query()
            ->where('supplier_id', $supplierId)
            ->get()
            ->keyBy('weekday');
    }

    /** @return Collection<string, DeadlineOverride> */
    private function overridesFor(int $supplierId): Collection
    {
        return $this->overrides[$supplierId] ??= DeadlineOverride::query()
            ->where('supplier_id', $supplierId)
            ->get()
            ->keyBy(fn (DeadlineOverride $override): string => $override->date->toDateString());
    }
}
