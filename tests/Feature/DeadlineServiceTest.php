<?php

namespace Tests\Feature;

use App\Exceptions\DeadlinePassedException;
use App\Models\DeadlineOverride;
use App\Models\DeadlineRule;
use App\Models\Supplier;
use App\Services\Deadlines\DeadlineService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeadlineServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Понеділок. */
    private const SERVICE_DATE = '2026-08-17';

    private Supplier $supplier;

    private DeadlineService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $this->service = new DeadlineService();
    }

    public function test_relative_rule_resolves_to_previous_day(): void
    {
        $this->rule(orderOffset: 1, orderTime: '09:00:00');

        $deadlines = $this->service->for($this->supplier, self::SERVICE_DATE);

        // Понеділок 17.08 → неділя 16.08, 09:00
        $this->assertSame('2026-08-16 09:00:00', $deadlines->orderAt->toDateTimeString());
        $this->assertSame('2026-08-16 09:00:00', $deadlines->cancelAt->toDateTimeString());
        $this->assertFalse($deadlines->fromOverride);
    }

    public function test_same_day_rule_is_supported(): void
    {
        $this->rule(orderOffset: 0, orderTime: '09:00:00', cancelOffset: 0, cancelTime: '09:00:00');

        $deadlines = $this->service->for($this->supplier, self::SERVICE_DATE);

        $this->assertSame('2026-08-17 09:00:00', $deadlines->orderAt->toDateTimeString());
    }

    public function test_override_wins_over_rule(): void
    {
        $this->rule();

        DeadlineOverride::create([
            'supplier_id' => $this->supplier->id,
            'date' => self::SERVICE_DATE,
            'order_deadline_at' => '2026-08-14 12:00:00',
            'cancel_deadline_at' => '2026-08-15 18:00:00',
            'reason' => 'Перед святом',
        ]);

        $deadlines = $this->service->for($this->supplier, self::SERVICE_DATE);

        $this->assertSame('2026-08-14 12:00:00', $deadlines->orderAt->toDateTimeString());
        $this->assertSame('2026-08-15 18:00:00', $deadlines->cancelAt->toDateTimeString());
        $this->assertTrue($deadlines->fromOverride);
    }

    public function test_partial_override_falls_back_to_rule_for_the_other_deadline(): void
    {
        $this->rule(orderOffset: 1, orderTime: '09:00:00');

        DeadlineOverride::create([
            'supplier_id' => $this->supplier->id,
            'date' => self::SERVICE_DATE,
            'order_deadline_at' => '2026-08-14 12:00:00',
        ]);

        $deadlines = $this->service->for($this->supplier, self::SERVICE_DATE);

        $this->assertSame('2026-08-14 12:00:00', $deadlines->orderAt->toDateTimeString());
        $this->assertSame('2026-08-16 09:00:00', $deadlines->cancelAt->toDateTimeString());
    }

    public function test_day_without_rule_is_closed(): void
    {
        // Правило лише на понеділок, питаємо про вівторок.
        $this->rule();

        $deadlines = $this->service->for($this->supplier, '2026-08-18');

        $this->assertFalse($deadlines->isConfigured());
        $this->assertFalse($deadlines->orderingOpen());
        $this->assertFalse($deadlines->cancellationOpen());
    }

    public function test_ordering_closes_exactly_at_the_deadline(): void
    {
        $this->rule(orderOffset: 1, orderTime: '09:00:00');

        CarbonImmutable::setTestNow('2026-08-16 08:59:59');
        $this->assertTrue($this->service->canOrder($this->supplier, self::SERVICE_DATE, CarbonImmutable::now()));

        CarbonImmutable::setTestNow('2026-08-16 09:00:00');
        $this->assertFalse($this->service->canOrder($this->supplier, self::SERVICE_DATE, CarbonImmutable::now()));

        CarbonImmutable::setTestNow();
    }

    public function test_assert_can_order_throws_after_deadline(): void
    {
        $this->rule(orderOffset: 1, orderTime: '09:00:00');

        $this->expectException(DeadlinePassedException::class);
        $this->expectExceptionMessage('Приймання замовлень на 17.08.2026 завершено.');

        $this->service->assertCanOrder($this->supplier, self::SERVICE_DATE, CarbonImmutable::parse('2026-08-16 10:00:00'));
    }

    public function test_assert_can_cancel_throws_after_deadline(): void
    {
        $this->rule(cancelOffset: 1, cancelTime: '09:00:00');

        $this->expectException(DeadlinePassedException::class);
        $this->expectExceptionMessage('Скасування замовлення на 17.08.2026 уже недоступне. Термін минув.');

        $this->service->assertCanCancel($this->supplier, self::SERVICE_DATE, CarbonImmutable::parse('2026-08-16 10:00:00'));
    }

    public function test_range_returns_a_deadline_for_every_day(): void
    {
        $this->rule();

        $range = $this->service->forRange($this->supplier, '2026-08-17', '2026-08-23');

        $this->assertCount(7, $range);
        $this->assertArrayHasKey('2026-08-17', $range);
        $this->assertArrayHasKey('2026-08-23', $range);
        $this->assertTrue($range['2026-08-17']->isConfigured());
        $this->assertFalse($range['2026-08-18']->isConfigured());
    }

    public function test_cancel_deadline_may_not_be_earlier_than_order_deadline(): void
    {
        // Скасування пізніше за замовлення — дозволено.
        $later = new DeadlineRule([
            'order_offset_days' => 1, 'order_time' => '09:00:00',
            'cancel_offset_days' => 0, 'cancel_time' => '09:00:00',
        ]);
        $this->assertTrue($later->cancelIsNotEarlierThanOrder());

        // Той самий день, пізніший час — дозволено.
        $sameDayLater = new DeadlineRule([
            'order_offset_days' => 1, 'order_time' => '09:00:00',
            'cancel_offset_days' => 1, 'cancel_time' => '12:00:00',
        ]);
        $this->assertTrue($sameDayLater->cancelIsNotEarlierThanOrder());

        // Скасування раніше за замовлення — заборонено.
        $earlier = new DeadlineRule([
            'order_offset_days' => 1, 'order_time' => '09:00:00',
            'cancel_offset_days' => 2, 'cancel_time' => '09:00:00',
        ]);
        $this->assertFalse($earlier->cancelIsNotEarlierThanOrder());
    }

    private function rule(
        int $weekday = 1,
        int $orderOffset = 1,
        string $orderTime = '09:00:00',
        ?int $cancelOffset = null,
        ?string $cancelTime = null,
    ): DeadlineRule {
        return DeadlineRule::create([
            'supplier_id' => $this->supplier->id,
            'weekday' => $weekday,
            'order_offset_days' => $orderOffset,
            'order_time' => $orderTime,
            'cancel_offset_days' => $cancelOffset ?? $orderOffset,
            'cancel_time' => $cancelTime ?? $orderTime,
        ]);
    }
}
