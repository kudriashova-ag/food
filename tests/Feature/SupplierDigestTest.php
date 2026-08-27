<?php

namespace Tests\Feature;

use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Mail\SupplierDigestMail;
use App\Models\DeadlineRule;
use App\Models\Dish;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\SupplierDigest;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Reports\SupplierCancellationAlerts;
use App\Services\Reports\SupplierDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupplierDigestTest extends TestCase
{
    use RefreshDatabase;

    /** Понеділок. */
    private const SERVICE_DATE = '2026-08-17';

    private Supplier $supplier;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.bot_username' => 'school_food_bot',
            'services.telegram.webhook_secret' => 'secret',
        ]);

        Http::fake(fn () => Http::response(['ok' => true]));

        // Неділя, 18:00 — вечір перед днем харчування.
        CarbonImmutable::setTestNow('2026-08-16 18:00:00');

        $this->supplier = Supplier::create([
            'name' => 'Смачно',
            'slug' => 'smachno',
            'report_emails' => 'kuhnya@example.com',
            'digest_time' => '18:00:00',
        ]);

        DeadlineRule::create([
            'supplier_id' => $this->supplier->id,
            'weekday' => 1,
            'order_offset_days' => 1,
            'order_time' => '09:00:00',
            'cancel_offset_days' => 1,
            'cancel_time' => '09:00:00',
        ]);

        $this->student = $this->makeStudent('Іваненко Марія');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_digest_goes_to_mail_and_telegram(): void
    {
        Mail::fake();

        $this->line('Куряча котлета', 2);
        $this->linkChat('555001');

        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        Mail::assertQueued(
            SupplierDigestMail::class,
            fn (SupplierDigestMail $mail): bool => $mail->hasTo('kuhnya@example.com'),
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'Куряча котлета'));

        $this->assertSame(1, SupplierDigest::query()->count());
    }

    public function test_digest_before_deadline_warns_that_numbers_may_change(): void
    {
        Mail::fake();

        $this->line('Куряча котлета', 1);

        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        // Дедлайн — 16.08 о 09:00, а зараз 18:00, тож приймання вже закрите.
        $this->assertTrue(SupplierDigest::query()->firstOrFail()->is_final);
    }

    public function test_preliminary_digest_is_followed_by_a_final_one(): void
    {
        Mail::fake();

        // Дедлайн «того ж дня до 09:00»: увечері напередодні приймання ще триває.
        DeadlineRule::query()->where('weekday', 1)->update(['order_offset_days' => 0]);

        $this->line('Куряча котлета', 1);

        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        $preliminary = SupplierDigest::query()->firstOrFail();
        $this->assertFalse($preliminary->is_final);

        // Наступного ранку приймання закрилося — йде уточнене зведення.
        CarbonImmutable::setTestNow('2026-08-17 09:30:00');
        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        $this->assertSame(2, SupplierDigest::query()->count());
        $this->assertTrue(SupplierDigest::query()->latest('id')->firstOrFail()->is_final);
    }

    public function test_final_digest_is_not_repeated(): void
    {
        Mail::fake();

        $this->line('Куряча котлета', 1);

        $this->artisan('school:send-supplier-digests')->assertSuccessful();
        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        $this->assertSame(1, SupplierDigest::query()->count());
    }

    public function test_no_orders_means_no_digest(): void
    {
        Mail::fake();

        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        Mail::assertNothingQueued();
        $this->assertSame(0, SupplierDigest::query()->count());
    }

    public function test_disabled_digest_is_skipped(): void
    {
        Mail::fake();

        $this->supplier->update(['digest_enabled' => false]);
        $this->line('Куряча котлета', 1);

        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        Mail::assertNothingQueued();
    }

    public function test_digest_counts_only_own_dishes(): void
    {
        Mail::fake();

        $other = Supplier::create(['name' => 'Домашня кухня', 'slug' => 'domashnya']);

        $this->line('Куряча котлета', 2);
        $this->line('Сирники', 5, supplier: $other);
        $this->linkChat('555001');

        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        Http::assertSent(fn ($request): bool => ! str_contains((string) ($request['text'] ?? ''), 'Сирники'));
    }

    public function test_report_emails_fall_back_to_the_account(): void
    {
        $this->supplier->update(['report_emails' => null]);

        User::create([
            'name' => 'Смачно',
            'email' => 'account@example.com',
            'password' => 'secret',
            'role' => UserRole::Supplier,
            'supplier_id' => $this->supplier->id,
        ]);

        $this->assertSame(['account@example.com'], $this->supplier->fresh()->reportRecipients());
    }

    public function test_several_report_emails_are_supported(): void
    {
        $this->supplier->update(['report_emails' => 'kuhnya@example.com, menedzher@example.com']);

        $this->assertSame(
            ['kuhnya@example.com', 'menedzher@example.com'],
            $this->supplier->fresh()->reportRecipients(),
        );
    }

    public function test_cancellation_before_the_digest_does_not_alert(): void
    {
        Mail::fake();

        $line = $this->line('Куряча котлета', 1);
        $line->update([
            'status' => OrderLineStatus::Cancelled,
            'cancelled_at' => now()->subHour(),
        ]);

        $this->artisan('school:send-supplier-digests')->assertSuccessful();
        $sent = app(SupplierCancellationAlerts::class)->dispatchPending();

        $this->assertSame(0, $sent);
    }

    public function test_cancellation_after_the_digest_alerts_the_kitchen(): void
    {
        Mail::fake();

        $this->line('Куряча котлета', 2);
        $this->linkChat('555001');

        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        CarbonImmutable::setTestNow('2026-08-16 19:00:00');

        $line = OrderLine::query()->firstOrFail();
        $line->update([
            'status' => OrderLineStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'Карантин у класі',
        ]);

        $sent = app(SupplierCancellationAlerts::class)->dispatchPending();

        $this->assertSame(1, $sent);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'sendMessage')
            && str_contains((string) ($request['text'] ?? ''), 'Скасування'));
    }

    public function test_the_same_cancellation_is_not_reported_twice(): void
    {
        Mail::fake();

        $this->line('Куряча котлета', 1);
        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        CarbonImmutable::setTestNow('2026-08-16 19:00:00');

        OrderLine::query()->firstOrFail()->update([
            'status' => OrderLineStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $alerts = app(SupplierCancellationAlerts::class);

        $this->assertSame(1, $alerts->dispatchPending());
        $this->assertSame(0, $alerts->dispatchPending());
    }

    public function test_cancellation_alerts_can_be_switched_off(): void
    {
        Mail::fake();

        $this->supplier->update(['cancellation_alerts_enabled' => false]);
        $this->line('Куряча котлета', 1);
        $this->artisan('school:send-supplier-digests')->assertSuccessful();

        CarbonImmutable::setTestNow('2026-08-16 19:00:00');

        OrderLine::query()->firstOrFail()->update([
            'status' => OrderLineStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $this->assertSame(0, app(SupplierCancellationAlerts::class)->dispatchPending());
    }

    public function test_digest_can_be_requested_for_any_date(): void
    {
        $this->line('Куряча котлета', 3);

        $text = app(SupplierDigestService::class)
            ->textFor($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));

        $this->assertStringContainsString('Куряча котлета', $text);
        $this->assertStringContainsString('3', $text);
    }

    private function makeStudent(string $name): Student
    {
        $user = User::create([
            'name' => $name,
            'login' => uniqid('student-'),
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => SchoolClass::query()->firstOrCreate([
                'academic_year' => 2026, 'grade' => 5, 'letter' => 'А',
            ])->id,
        ]);
    }

    private function line(string $dishName, int $quantity, ?Supplier $supplier = null): OrderLine
    {
        $supplier ??= $this->supplier;

        $dish = Dish::query()->firstOrCreate(
            ['supplier_id' => $supplier->id, 'name' => $dishName],
            ['price' => 60],
        );

        $order = Order::query()->firstOrCreate(
            ['number' => 'ЗМ-TEST-'.$this->student->id],
            [
                'student_id' => $this->student->id,
                'school_class_id' => $this->student->school_class_id,
                'placed_at' => now(),
            ],
        );

        return $order->lines()->create([
            'student_id' => $this->student->id,
            'supplier_id' => $supplier->id,
            'service_date' => self::SERVICE_DATE,
            'dish_id' => $dish->id,
            'dish_name' => $dishName,
            'quantity' => $quantity,
            'unit_price' => 60,
        ]);
    }

    private function linkChat(string $chatId): TelegramLink
    {
        return TelegramLink::create([
            'supplier_id' => $this->supplier->id,
            'chat_id' => $chatId,
            'is_active' => true,
            'linked_at' => now(),
        ]);
    }
}
