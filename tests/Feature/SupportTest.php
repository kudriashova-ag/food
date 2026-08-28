<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\SupportRequestMail;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Форма «Допомога»: питання лишається в базі, а адміністратор дізнається
 * про нього поштою й у Telegram.
 */
class SupportTest extends TestCase
{
    use RefreshDatabase;

    /** Http::fake() домішує стаби, а не заміщає їх — статус тримаємо тут. */
    private int $telegramStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake(fn () => Http::response(['ok' => $this->telegramStatus === 200], $this->telegramStatus));

        Setting::put('support_email', 'admin@school.test');
        Setting::put('support_telegram_chat_id', '555000');
        config(['services.telegram.bot_token' => 'test-token']);
    }

    public function test_guest_opens_the_service_info(): void
    {
        $this->get(route('support.info'))
            ->assertOk()
            ->assertSee('Як замовити')
            ->assertSee('Скасування');
    }

    public function test_school_can_add_its_own_text_to_the_info_page(): void
    {
        Setting::put('service_info_text', 'Їдальня працює з 8:00.');

        $this->get(route('support.info'))
            ->assertOk()
            ->assertSee('Їдальня працює з 8:00.');
    }

    public function test_guest_sends_a_question(): void
    {
        $this->post(route('support.store'), [
            'name' => 'Марія',
            'email' => 'mariia@example.com',
            'message' => 'Як скасувати замовлення на завтра?',
        ])
            ->assertRedirect(route('support.help'))
            ->assertSessionHas('status');

        $request = SupportRequest::query()->sole();

        $this->assertSame('Марія', $request->name);
        $this->assertNull($request->user_id);
        $this->assertNotNull($request->notified_at);

        Mail::assertQueued(SupportRequestMail::class);
    }

    public function test_question_reaches_the_admin_telegram(): void
    {
        $this->post(route('support.store'), [
            'name' => 'Марія',
            'email' => 'mariia@example.com',
            'message' => 'Питання про меню на понеділок.',
        ])->assertRedirect();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && $request['chat_id'] === '555000'
            && str_contains($request['text'], 'Питання про меню'));
    }

    public function test_logged_in_student_is_linked_to_the_question(): void
    {
        $user = $this->student();

        $this->actingAs($user)->post(route('support.store'), [
            'name' => 'Іваненко Марія',
            'email' => 'mariia@example.com',
            'message' => 'Не приходять сповіщення на пошту.',
        ])->assertRedirect();

        $this->assertSame($user->id, SupportRequest::query()->sole()->user_id);
    }

    public function test_form_is_prefilled_for_a_logged_in_student(): void
    {
        $this->actingAs($this->student())
            ->get(route('support.help'))
            ->assertOk()
            ->assertSee('Іваненко Марія');
    }

    public function test_short_message_is_rejected(): void
    {
        $this->post(route('support.store'), [
            'name' => 'Марія',
            'email' => 'mariia@example.com',
            'message' => 'Коротко',
        ])->assertSessionHasErrors('message');

        $this->assertSame(0, SupportRequest::query()->count());
    }

    public function test_question_survives_a_channel_failure(): void
    {
        // Пошта не налаштована, Telegram відповідає помилкою — питання все одно
        // має лишитися в базі, інакше воно просто зникне.
        Setting::put('support_email', '');
        $this->telegramStatus = 500;

        $this->post(route('support.store'), [
            'name' => 'Марія',
            'email' => 'mariia@example.com',
            'message' => 'Питання, яке не має загубитися.',
        ])->assertRedirect();

        $request = SupportRequest::query()->sole();

        $this->assertNull($request->notified_at);
        $this->assertSame('Питання, яке не має загубитися.', $request->message);
    }

    public function test_flood_is_throttled(): void
    {
        foreach (range(1, 5) as $i) {
            $this->post(route('support.store'), [
                'name' => 'Марія',
                'email' => 'mariia@example.com',
                'message' => "Питання номер {$i} про харчування.",
            ])->assertRedirect();
        }

        $this->post(route('support.store'), [
            'name' => 'Марія',
            'email' => 'mariia@example.com',
            'message' => 'Шосте питання поспіль про харчування.',
        ])->assertSessionHasErrors('message');

        $this->assertSame(5, SupportRequest::query()->count());
    }

    private function student(): User
    {
        $user = User::create([
            'name' => 'Іваненко Марія',
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        Student::create([
            'user_id' => $user->id,
            'full_name' => 'Іваненко Марія',
            'school_class_id' => SchoolClass::create([
                'grade' => 5, 'letter' => 'А', 'academic_year' => 2026,
            ])->id,
            'consent_at' => now(),
            'consent_ip' => '127.0.0.1',
        ]);

        return $user;
    }
}
