<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Вчителі отримують фіксований пароль при імпорті (TeacherImportService) —
 * систему повинна змусити задати власний пароль одразу після входу.
 */
class MustChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Коваленко Ольга',
            'login' => 'kovalenko.olha',
            'password' => 'food-teacher-2026',
            'role' => UserRole::Student,
            'must_change_password' => true,
        ]);

        Student::create([
            'user_id' => $this->user->id,
            'full_name' => 'Коваленко Ольга',
            'school_class_id' => null,
            'consent_at' => now(),
        ]);
    }

    public function test_login_redirects_straight_to_settings_with_a_temporary_password(): void
    {
        $this->post(route('login'), [
            'login' => 'kovalenko.olha',
            'password' => 'food-teacher-2026',
        ])
            ->assertRedirect(route('settings'))
            ->assertSessionHas('error');
    }

    public function test_other_pages_bounce_back_to_settings_until_password_is_changed(): void
    {
        $this->actingAs($this->user);

        $this->get(route('orders.index'))
            ->assertRedirect(route('settings'))
            ->assertSessionHas('error');
    }

    public function test_settings_page_itself_is_reachable(): void
    {
        $this->actingAs($this->user);

        $this->get(route('settings'))->assertOk();
    }

    public function test_changing_the_password_clears_the_flag_and_unlocks_the_rest(): void
    {
        $this->actingAs($this->user);

        $this->patch(route('settings.password'), [
            'current_password' => 'food-teacher-2026',
            'password' => 'MyOwnPassword1',
            'password_confirmation' => 'MyOwnPassword1',
        ])->assertRedirect();

        $this->assertFalse($this->user->fresh()->must_change_password);

        $this->get(route('orders.index'))->assertOk();
    }

    public function test_regular_student_without_the_flag_is_not_affected(): void
    {
        $regular = User::create([
            'name' => 'Петренко Іван',
            'login' => 'petrenko.ivan',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        Student::create([
            'user_id' => $regular->id,
            'full_name' => 'Петренко Іван',
            'consent_at' => now(),
        ]);

        $this->actingAs($regular);

        $this->get(route('orders.index'))->assertOk();
    }
}
