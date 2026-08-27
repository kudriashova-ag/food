<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Supplier\Pages\ManageDeadlines;
use App\Filament\Supplier\Resources\DeadlineOverrides\Pages\CreateDeadlineOverride;
use App\Models\DeadlineOverride;
use App\Models\DeadlineRule;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Deadlines\DeadlineService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SupplierDeadlinesTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('supplier');

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $this->actingAs(User::create([
            'name' => 'Смачно',
            'email' => 'smachno@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $this->supplier->id,
        ]));
    }

    public function test_rules_are_saved_per_weekday(): void
    {
        Livewire::test(ManageDeadlines::class)
            ->set('data.rules.1.enabled', true)
            ->set('data.rules.1.order_offset_days', 1)
            ->set('data.rules.1.order_time', '09:00')
            ->set('data.rules.1.cancel_offset_days', 0)
            ->set('data.rules.1.cancel_time', '08:00')
            ->call('save')
            ->assertHasNoErrors();

        $rule = DeadlineRule::query()
            ->where('supplier_id', $this->supplier->id)
            ->where('weekday', 1)
            ->firstOrFail();

        $this->assertSame(1, $rule->order_offset_days);
        $this->assertSame(0, $rule->cancel_offset_days);
    }

    public function test_disabled_weekday_removes_the_rule(): void
    {
        DeadlineRule::create([
            'supplier_id' => $this->supplier->id,
            'weekday' => 3,
            'order_offset_days' => 1,
            'order_time' => '09:00:00',
            'cancel_offset_days' => 1,
            'cancel_time' => '09:00:00',
        ]);

        Livewire::test(ManageDeadlines::class)
            ->assertSet('data.rules.3.enabled', true)
            ->set('data.rules.3.enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, DeadlineRule::query()->where('weekday', 3)->count());
    }

    public function test_cancel_deadline_earlier_than_order_is_rejected(): void
    {
        Livewire::test(ManageDeadlines::class)
            ->set('data.rules.1.enabled', true)
            ->set('data.rules.1.order_offset_days', 1)
            ->set('data.rules.1.order_time', '09:00')
            ->set('data.rules.1.cancel_offset_days', 2)
            ->set('data.rules.1.cancel_time', '09:00')
            ->call('save')
            ->assertHasErrors('data.rules.1.cancel_time');

        $this->assertSame(0, DeadlineRule::query()->count());
    }

    public function test_override_is_created_for_the_current_supplier(): void
    {
        Livewire::test(CreateDeadlineOverride::class)
            ->fillForm([
                'date' => '2026-12-24',
                'order_deadline_at' => '2026-12-22 12:00:00',
                'cancel_deadline_at' => '2026-12-23 12:00:00',
                'reason' => 'Перед святом',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $override = DeadlineOverride::query()->firstOrFail();

        $this->assertSame($this->supplier->id, $override->supplier_id);
        $this->assertSame(auth()->id(), $override->created_by);
    }

    public function test_override_with_cancel_before_order_is_rejected(): void
    {
        Livewire::test(CreateDeadlineOverride::class)
            ->fillForm([
                'date' => '2026-12-24',
                'order_deadline_at' => '2026-12-23 12:00:00',
                'cancel_deadline_at' => '2026-12-22 12:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['cancel_deadline_at']);
    }

    public function test_saved_rule_is_immediately_visible_to_the_service(): void
    {
        Livewire::test(ManageDeadlines::class)
            ->set('data.rules.1.enabled', true)
            ->set('data.rules.1.order_offset_days', 2)
            ->set('data.rules.1.order_time', '10:00')
            ->set('data.rules.1.cancel_offset_days', 1)
            ->set('data.rules.1.cancel_time', '10:00')
            ->call('save')
            ->assertHasNoErrors();

        // 17.08.2026 — понеділок
        $deadlines = app(DeadlineService::class)->for($this->supplier, '2026-08-17');

        $this->assertSame('2026-08-15 10:00:00', $deadlines->orderAt->toDateTimeString());
        $this->assertSame('2026-08-16 10:00:00', $deadlines->cancelAt->toDateTimeString());
    }
}
