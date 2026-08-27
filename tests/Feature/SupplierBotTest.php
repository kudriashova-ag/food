<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Dish;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\TelegramLinkService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupplierBotTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'webhook-secret';

    private const SERVICE_DATE = '2026-08-17';

    private Supplier $supplier;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_username' => 'school_food_bot',
            'services.telegram.webhook_secret' => self::SECRET,
        ]);

        Http::fake(fn () => Http::response(['ok' => true]));

        CarbonImmutable::setTestNow('2026-08-16 12:00:00');

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);
        $this->student = $this->makeStudent();

        $this->orderLine('Куряча котлета', 3);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_supplier_chat_is_linked_and_gets_the_keyboard(): void
    {
        $token = app(TelegramLinkService::class)->issueToken($this->supplier);

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'message' => [
                'chat' => ['id' => 777001, 'type' => 'private', 'username' => 'kuhar'],
                'text' => '/start '.$token->token,
            ],
        ])->assertOk();

        $link = TelegramLink::query()->firstOrFail();

        $this->assertSame($this->supplier->id, $link->supplier_id);
        $this->assertNull($link->student_id);

        // Клавіатура надіслана разом із привітанням.
        Http::assertSent(fn ($request): bool => isset($request['reply_markup']['inline_keyboard']));
    }

    public function test_tomorrow_button_returns_the_summary(): void
    {
        $this->linkSupplierChat('777001');

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'callback_query' => [
                'id' => 'cb-1',
                'data' => 'digest:tomorrow',
                'message' => ['chat' => ['id' => 777001, 'type' => 'private']],
            ],
        ])->assertOk();

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Куряча котлета'));
    }

    public function test_today_button_uses_todays_date(): void
    {
        $this->linkSupplierChat('777001');
        $this->orderLine('Сирники', 2, date: '2026-08-16');

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'callback_query' => [
                'id' => 'cb-2',
                'data' => 'digest:today',
                'message' => ['chat' => ['id' => 777001, 'type' => 'private']],
            ],
        ])->assertOk();

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Сирники'));
    }

    public function test_custom_date_is_parsed(): void
    {
        $this->linkSupplierChat('777001');

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'callback_query' => [
                'id' => 'cb-3',
                'data' => 'digest:pick',
                'message' => ['chat' => ['id' => 777001, 'type' => 'private']],
            ],
        ])->assertOk();

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'message' => [
                'chat' => ['id' => 777001, 'type' => 'private'],
                'text' => '17.08',
            ],
        ])->assertOk();

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Куряча котлета'));
    }

    public function test_unparsable_date_asks_again(): void
    {
        $this->linkSupplierChat('777001');

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'callback_query' => [
                'id' => 'cb-4',
                'data' => 'digest:pick',
                'message' => ['chat' => ['id' => 777001, 'type' => 'private']],
            ],
        ])->assertOk();

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'message' => [
                'chat' => ['id' => 777001, 'type' => 'private'],
                'text' => 'позавчора',
            ],
        ])->assertOk();

        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Не розпізнав дату'));
    }

    public function test_parent_chat_gets_no_supplier_data(): void
    {
        // Чат батьків: прив'язаний до учня, не до постачальника.
        TelegramLink::create([
            'student_id' => $this->student->id,
            'chat_id' => '555001',
            'is_active' => true,
            'linked_at' => now(),
        ]);

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'callback_query' => [
                'id' => 'cb-5',
                'data' => 'digest:tomorrow',
                'message' => ['chat' => ['id' => 555001, 'type' => 'private']],
            ],
        ])->assertOk();

        Http::assertNotSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Куряча котлета'));
    }

    public function test_start_in_a_linked_supplier_chat_shows_the_menu(): void
    {
        $this->linkSupplierChat('777001');

        $this->postJson('/telegram/webhook/'.self::SECRET, [
            'message' => [
                'chat' => ['id' => 777001, 'type' => 'private'],
                'text' => '/start',
            ],
        ])->assertOk();

        Http::assertSent(fn ($request): bool => isset($request['reply_markup']['inline_keyboard']));
    }

    private function linkSupplierChat(string $chatId): TelegramLink
    {
        return TelegramLink::create([
            'supplier_id' => $this->supplier->id,
            'chat_id' => $chatId,
            'is_active' => true,
            'linked_at' => now(),
        ]);
    }

    private function makeStudent(): Student
    {
        $user = User::create([
            'name' => 'Іваненко Марія',
            'login' => 'ivanenko.mariia',
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'full_name' => 'Іваненко Марія',
            'school_class_id' => SchoolClass::create([
                'grade' => 5, 'letter' => 'А', 'academic_year' => 2026,
            ])->id,
        ]);
    }

    private function orderLine(string $dishName, int $quantity, string $date = self::SERVICE_DATE): void
    {
        $dish = Dish::query()->firstOrCreate(
            ['supplier_id' => $this->supplier->id, 'name' => $dishName],
            ['price' => 60],
        );

        $order = Order::query()->firstOrCreate(
            ['number' => 'ЗМ-TEST-1'],
            [
                'student_id' => $this->student->id,
                'school_class_id' => $this->student->school_class_id,
                'placed_at' => now(),
            ],
        );

        $order->lines()->create([
            'student_id' => $this->student->id,
            'supplier_id' => $this->supplier->id,
            'service_date' => $date,
            'dish_id' => $dish->id,
            'dish_name' => $dishName,
            'quantity' => $quantity,
            'unit_price' => 60,
        ]);
    }
}
