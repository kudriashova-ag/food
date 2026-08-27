<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Supplier;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reaches_only_the_school_panel(): void
    {
        $admin = User::create([
            'name' => 'Адміністратор',
            'email' => 'admin@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]);

        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($admin->canAccessPanel(Filament::getPanel('supplier')));
    }

    public function test_supplier_reaches_only_its_own_panel(): void
    {
        $supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $user = User::create([
            'name' => 'Смачно',
            'email' => 'smachno@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $supplier->id,
        ]);

        $this->assertTrue($user->canAccessPanel(Filament::getPanel('supplier')));
        $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_student_reaches_no_panel(): void
    {
        $student = User::create([
            'name' => 'Іваненко Марія',
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        $this->assertFalse($student->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($student->canAccessPanel(Filament::getPanel('supplier')));
    }

    public function test_supplier_user_opens_supplier_panel_over_http(): void
    {
        $supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno-http']);

        $user = User::create([
            'name' => 'Смачно',
            'email' => 'smachno-http@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $supplier->id,
        ]);

        $this->actingAs($user)->get('/supplier')->assertSuccessful();
    }

    public function test_admin_gets_forbidden_on_supplier_panel_over_http(): void
    {
        $admin = User::create([
            'name' => 'Адміністратор',
            'email' => 'admin-http@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)->get('/supplier')
            ->assertForbidden()
            ->assertSee('Цей кабінет вам недоступний')
            ->assertSee('Перейти в адмінпанель');
    }

    public function test_logout_clears_the_session(): void
    {
        $admin = User::create([
            'name' => 'Адміністратор',
            'email' => 'admin-logout@test.local',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_deactivated_account_reaches_no_panel(): void
    {
        $supplier = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        $user = User::create([
            'name' => 'Домашня кухня',
            'email' => 'domashnya@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $supplier->id,
            'is_active' => false,
        ]);

        $this->assertFalse($user->canAccessPanel(Filament::getPanel('supplier')));
    }
}
