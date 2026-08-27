<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Іваненко Марія',
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        $this->student = Student::create([
            'user_id' => $this->user->id,
            'full_name' => 'Іваненко Марія',
            'school_class_id' => SchoolClass::create([
                'grade' => 5, 'letter' => 'А', 'academic_year' => 2026,
            ])->id,
        ]);
    }

    public function test_student_logs_in_with_login_not_email(): void
    {
        $this->post('/login', [
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($this->user);
        $this->assertNotNull($this->student->fresh()->first_login_at);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->post('/login', [
            'login' => 'ivanenko.mariia',
            'password' => 'wrong',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_deactivated_student_cannot_log_in(): void
    {
        $this->user->update(['is_active' => false]);

        $this->post('/login', [
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
        ])->assertSessionHasErrors('login');

        $this->assertGuest();
    }

    public function test_login_attempts_are_throttled(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['login' => 'ivanenko.mariia', 'password' => 'wrong']);
        }

        $response = $this->post('/login', ['login' => 'ivanenko.mariia', 'password' => 'secret']);

        $response->assertSessionHasErrors('login');
        $this->assertStringContainsString(
            'Забагато спроб входу',
            session('errors')->first('login'),
        );
        $this->assertGuest();
    }

    public function test_first_visit_asks_for_consent(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertRedirect(route('consent.show'));
    }

    public function test_consent_is_recorded_with_date_and_ip(): void
    {
        $this->actingAs($this->user)
            ->post('/consent', ['agreed' => '1'])
            ->assertRedirect(route('home'));

        $student = $this->student->fresh();

        $this->assertNotNull($student->consent_at);
        $this->assertNotNull($student->consent_ip);
    }

    public function test_consent_cannot_be_skipped(): void
    {
        $this->actingAs($this->user)
            ->post('/consent', [])
            ->assertSessionHasErrors('agreed');

        $this->assertNull($this->student->fresh()->consent_at);
    }

    public function test_home_lists_only_visible_suppliers(): void
    {
        $this->student->update(['consent_at' => now(), 'consent_ip' => '127.0.0.1']);

        Supplier::create(['name' => 'Смачно', 'slug' => 'smachno', 'is_visible' => true]);
        Supplier::create(['name' => 'Прихований', 'slug' => 'hidden', 'is_visible' => false]);

        $this->actingAs($this->user)
            ->get('/')
            ->assertOk()
            ->assertSee('Смачно')
            ->assertDontSee('Прихований');
    }

    public function test_supplier_account_cannot_open_the_student_pages(): void
    {
        $supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $supplierUser = User::create([
            'name' => 'Смачно',
            'email' => 'smachno@test.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $supplier->id,
        ]);

        // Вітрина відкрита всім, але кабінет учня — ні.
        $this->actingAs($supplierUser)->get('/')->assertOk();
        $this->actingAs($supplierUser)->get(route('orders.index'))->assertForbidden();
    }

    public function test_guest_browses_suppliers_without_logging_in(): void
    {
        Supplier::create(['name' => 'Смачно', 'slug' => 'smachno', 'is_visible' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Смачно');
    }

    public function test_guest_is_sent_to_login_only_on_the_student_pages(): void
    {
        $this->get(route('orders.index'))->assertRedirect(route('login'));
        $this->get(route('settings'))->assertRedirect(route('login'));
        $this->post(route('orders.store'))->assertRedirect(route('login'));
    }
}
