<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Supplier\Pages\NotificationSettings;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Постачальник теж може редагувати реквізити для оплати — те саме поле, що й в адмінці школи. */
class SupplierNotificationSettingsPaymentDetailsTest extends TestCase
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

    public function test_supplier_sees_its_current_payment_details(): void
    {
        $this->supplier->update(['payment_details' => 'IBAN UA000000000000000000000000000']);

        Livewire::test(NotificationSettings::class)
            ->assertFormSet(['payment_details' => 'IBAN UA000000000000000000000000000']);
    }

    public function test_supplier_saves_its_payment_details(): void
    {
        Livewire::test(NotificationSettings::class)
            ->fillForm(['payment_details' => "ФОП Смачненко\nIBAN UA111111111111111111111111111"])
            ->call('save');

        $this->assertSame(
            "ФОП Смачненко\nIBAN UA111111111111111111111111111",
            $this->supplier->fresh()->payment_details,
        );
    }

    public function test_blank_payment_details_are_saved_as_null(): void
    {
        $this->supplier->update(['payment_details' => 'Old value']);

        Livewire::test(NotificationSettings::class)
            ->fillForm(['payment_details' => ''])
            ->call('save');

        $this->assertNull($this->supplier->fresh()->payment_details);
    }
}
