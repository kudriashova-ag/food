<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\NotificationLog;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\TelegramLink;
use App\Models\TelegramLinkToken;
use App\Models\User;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\DeadlineReminder;
use App\Services\Telegram\TelegramLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret';

    private User $user;

    private Student $student;

    /** Відповідь Telegram — тести змінюють її, щоб перевірити 403. */
    private int $telegramStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_username' => 'school_food_bot',
            'services.telegram.webhook_secret' => self::SECRET,
        ]);

        // Стаб один на весь тест: повторний Http::fake не перекриває перший.
        Http::fake(fn () => Http::response(
            ['ok' => $this->telegramStatus === 200],
            $this->telegramStatus,
        ));

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
            'consent_at' => now(),
            'consent_ip' => '127.0.0.1',
        ]);
    }

    public function test_deep_link_contains_a_fresh_token(): void
    {
        $link = app(TelegramLinkService::class)->deepLinkFor($this->student);

        $token = TelegramLinkToken::query()->firstOrFail();

        $this->assertStringContainsString('t.me/school_food_bot?start='.$token->token, $link);
        $this->assertTrue($token->isUsable());
    }

    public function test_issuing_a_new_token_burns_the_previous_one(): void
    {
        $service = app(TelegramLinkService::class);

        $first = $service->issueToken($this->student);
        $service->issueToken($this->student);

        $this->assertFalse($first->fresh()->isUsable());
    }

    public function test_start_command_links_the_chat(): void
    {
        $token = app(TelegramLinkService::class)->issueToken($this->student);

        $this->postJson("/telegram/webhook/".self::SECRET, [
            'message' => [
                'chat' => ['id' => 555001, 'type' => 'private', 'username' => 'mama'],
                'text' => '/start '.$token->token,
            ],
        ])->assertOk();

        $link = TelegramLink::query()->firstOrFail();

        $this->assertSame('555001', $link->chat_id);
        $this->assertSame('mama', $link->username);
        $this->assertTrue($link->is_active);
        $this->assertFalse($token->fresh()->isUsable());
    }

    public function test_token_cannot_be_used_twice(): void
    {
        $token = app(TelegramLinkService::class)->issueToken($this->student);

        foreach ([555001, 555002] as $chatId) {
            $this->postJson('/telegram/webhook/'.self::SECRET, [
                'message' => [
                    'chat' => ['id' => $chatId, 'type' => 'private'],
                    'text' => '/start '.$token->token,
                ],
            ])->assertOk();
        }

        $this->assertSame(1, TelegramLink::query()->count());
    }

    public function test_expired_token_is_rejected(): void
    {
        $token = app(TelegramLinkService::class)->issueToken($this->student);

        CarbonImmutable::setTestNow(now()->addMinutes(TelegramLinkService::TOKEN_TTL_MINUTES + 1));

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'message' => [
                'chat' => ['id' => 555001, 'type' => 'private'],
                'text' => '/start '.$token->token,
            ],
        ])->assertOk();

        $this->assertSame(0, TelegramLink::query()->count());

        CarbonImmutable::setTestNow();
    }

    public function test_group_chats_are_ignored(): void
    {
        $token = app(TelegramLinkService::class)->issueToken($this->student);

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'message' => [
                'chat' => ['id' => -100500, 'type' => 'group'],
                'text' => '/start '.$token->token,
            ],
        ])->assertOk();

        $this->assertSame(0, TelegramLink::query()->count());
    }

    public function test_wrong_webhook_secret_is_not_found(): void
    {
        $this->postJson('/telegram/webhook/wrong-secret', [
            'message' => [
                'chat' => ['id' => 555001, 'type' => 'private'],
                'text' => '/start whatever',
            ],
        ])->assertNotFound();
    }

    public function test_several_chats_can_be_linked_to_one_student(): void
    {
        $service = app(TelegramLinkService::class);

        foreach ([555001 => 'mama', 555002 => 'tato'] as $chatId => $username) {
            $token = $service->issueToken($this->student);

            $this->postJson('/telegram/webhook/'.self::SECRET, [
                'message' => [
                    'chat' => ['id' => $chatId, 'type' => 'private', 'username' => $username],
                    'text' => '/start '.$token->token,
                ],
            ])->assertOk();
        }

        $this->assertSame(2, $this->student->telegramLinks()->count());
    }

    public function test_notification_reaches_every_active_chat(): void
    {
        $this->linkChat('555001');
        $this->linkChat('555002');

        $this->user->notify(new DeadlineReminder(CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-16 09:00')));

        Http::assertSentCount(2);
        $this->assertSame(2, NotificationLog::query()->where('channel', 'telegram')->count());
    }

    public function test_blocked_bot_deactivates_the_link(): void
    {
        $this->telegramStatus = 403;

        $link = $this->linkChat('555001');

        $this->user->notify(new DeadlineReminder(CarbonImmutable::parse('2026-08-17'), CarbonImmutable::parse('2026-08-16 09:00')));

        $link->refresh();

        $this->assertFalse($link->is_active);
        $this->assertNotNull($link->deactivated_at);
        $this->assertSame('failed', NotificationLog::query()->where('channel', 'telegram')->firstOrFail()->status);
    }

    public function test_student_without_email_still_gets_telegram(): void
    {
        $this->linkChat('555001');

        $this->assertTrue($this->student->isNotifiable());
        $this->assertContains(
            TelegramChannel::class,
            (new DeadlineReminder(CarbonImmutable::now(), CarbonImmutable::now()->addHour()))->via($this->user),
        );
    }

    public function test_student_cannot_disconnect_a_foreign_link(): void
    {
        $link = $this->linkChat('555001');

        $otherUser = User::create([
            'name' => 'Петренко Іван',
            'login' => 'petrenko.ivan',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        Student::create([
            'user_id' => $otherUser->id,
            'full_name' => 'Петренко Іван',
            'consent_at' => now(),
            'consent_ip' => '127.0.0.1',
        ]);

        $this->actingAs($otherUser)
            ->delete(route('settings.telegram.disconnect', $link))
            ->assertForbidden();

        $this->assertSame(1, TelegramLink::query()->count());
    }

    public function test_settings_page_shows_linked_chats(): void
    {
        $this->linkChat('555001', 'mama');

        $this->actingAs($this->user)
            ->get(route('settings'))
            ->assertOk()
            ->assertSee('@mama');
    }

    private function linkChat(string $chatId, ?string $username = null): TelegramLink
    {
        return TelegramLink::create([
            'student_id' => $this->student->id,
            'chat_id' => $chatId,
            'username' => $username,
            'is_active' => true,
            'linked_at' => now(),
        ]);
    }
}
