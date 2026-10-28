<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\UserRole;
use App\Jobs\AppendToGoogleSheetJournal;
use App\Jobs\SyncWeekToGoogleSheet;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\OrderSheetSync;
use App\Services\GoogleSheets\OrdersWorkbook;
use App\Services\Orders\CancellationService;
use App\Services\Orders\OrderDeletion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleSheetsSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_DATE = '2026-09-08';   // вівторок; тиждень 07.09–11.09

    private const SHEET_ID = 'TestSheetId1234567890abcdef';

    private Supplier $supplier;

    private Student $student;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create(['name' => 'Смачно', 'slug' => 'smachno']);

        $user = User::create(['name' => 'Іваненко Марія', 'login' => 'ivanenko.mariia', 'password' => 'secret', 'role' => UserRole::Student]);

        $this->student = Student::create([
            'user_id' => $user->id,
            'full_name' => 'Іваненко Марія',
            'school_class_id' => SchoolClass::create(['grade' => 5, 'letter' => 'А', 'academic_year' => 2026])->id,
        ]);
    }

    public function test_nothing_is_queued_until_the_sheet_is_configured(): void
    {
        Queue::fake();

        OrderSheetSync::placed($this->order());

        Queue::assertNothingPushed();
    }

    public function test_placed_order_goes_to_the_journal_and_its_week(): void
    {
        $this->configure();
        Queue::fake();

        $order = $this->order();
        OrderSheetSync::placed($order);

        Queue::assertPushed(AppendToGoogleSheetJournal::class, function (AppendToGoogleSheetJournal $job) use ($order): bool {
            $row = $job->rows[0];

            return $row[1] === 'Замовлено'
                && $row[2] === $order->number
                && $row[3] === 'Іваненко Марія'
                && $row[4] === '5-А'
                && $row[5] === 'Смачно'
                && $row[6] === '08.09.2026'
                && $row[8] === 'Борщ';
        });

        Queue::assertPushed(SyncWeekToGoogleSheet::class, fn (SyncWeekToGoogleSheet $job): bool => $job->supplierId === $this->supplier->id && $job->weekStart === '2026-09-07');
    }

    public function test_cancellation_is_journaled_with_who_and_why(): void
    {
        $this->configure();
        Notification::fake();
        Queue::fake();

        $line = $this->order()->lines->first();
        $admin = User::create(['name' => 'Адміністратор', 'email' => 'admin@test.local', 'password' => 'secret', 'role' => UserRole::Admin]);

        app(CancellationService::class)->cancelLine($line, $admin, reason: 'Хворіє', bypassDeadline: true);

        Queue::assertPushed(AppendToGoogleSheetJournal::class, fn (AppendToGoogleSheetJournal $job): bool => $job->rows[0][1] === 'Скасовано'
            && $job->rows[0][11] === 'Адміністратор'
            && $job->rows[0][12] === 'Хворіє');
        Queue::assertPushed(SyncWeekToGoogleSheet::class);
    }

    public function test_deleted_order_is_journaled_and_its_week_refreshed(): void
    {
        $this->configure();
        Queue::fake();

        $order = $this->order();
        OrderDeletion::delete($order);

        $this->assertModelMissing($order);
        Queue::assertPushed(AppendToGoogleSheetJournal::class, fn (AppendToGoogleSheetJournal $job): bool => $job->rows[0][1] === 'Видалено' && $job->rows[0][8] === 'Борщ');
        Queue::assertPushed(SyncWeekToGoogleSheet::class, fn (SyncWeekToGoogleSheet $job): bool => $job->weekStart === '2026-09-07');
    }

    public function test_week_tab_is_created_filled_and_framed(): void
    {
        $this->configure();
        $this->order();
        $this->fakeGoogle(sheets: []);

        app(OrdersWorkbook::class)->writeWeek($this->supplier, \Carbon\CarbonImmutable::parse(self::SERVICE_DATE));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':batchUpdate')
            && data_get($request->data(), 'requests.0.addSheet.properties.title') === 'Смачно 07.09–11.09.2026');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && data_get($request->data(), 'values.0.1') === "Прізвище, ім'я"
            && data_get($request->data(), 'values.2.1') === 'Іваненко Марія');

        // Очищення вкладки: тіло — об'єкт «{}», на «[]» Google відповідає 400.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':clear') && $request->body() === '{}');

        // Товста рамка ліворуч від першої колонки дня.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':batchUpdate')
            && str_contains(json_encode($request->data()), 'SOLID_THICK'));
    }

    public function test_current_week_tabs_are_moved_first(): void
    {
        $this->configure();
        $this->travelTo('2026-09-08 10:00');

        $this->fakeGoogle(sheets: [
            ['sheetId' => 1, 'title' => 'Смачно 31.08–04.09.2026', 'index' => 0],
            ['sheetId' => 2, 'title' => OrdersWorkbook::JOURNAL_TITLE, 'index' => 1],
            ['sheetId' => 3, 'title' => 'Смачно 07.09–11.09.2026', 'index' => 2],
        ]);

        app(OrdersWorkbook::class)->reorderTabs();

        Http::assertSent(function (Request $request): bool {
            $moves = collect(data_get($request->data(), 'requests', []))
                ->mapWithKeys(fn (array $r): array => [data_get($r, 'updateSheetProperties.properties.sheetId') => data_get($r, 'updateSheetProperties.properties.index')]);

            return $moves->get(3) === 0 && $moves->get(2) === 1 && $moves->get(1) === 2;
        });
    }

    public function test_journal_tab_is_created_with_a_header(): void
    {
        $this->configure();
        $this->fakeGoogle(sheets: []);

        app(OrdersWorkbook::class)->appendJournal([['08.09.2026 10:00:00', 'Замовлено']]);

        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'requests.0.addSheet.properties.title') === 'Журнал змін');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && data_get($request->data(), 'values.0') === OrdersWorkbook::JOURNAL_HEADER);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':append') && data_get($request->data(), 'values.0.1') === 'Замовлено');
    }

    public function test_access_token_is_a_signed_jwt_and_is_cached(): void
    {
        $this->configure();
        $this->fakeGoogle(sheets: []);

        $client = app(GoogleSheetsClient::class);
        $client->sheets();
        $client->sheets();

        $tokenRequests = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com'));
        $this->assertCount(1, $tokenRequests);

        [$header, $claims, $signature] = explode('.', $tokenRequests->first()[0]['assertion']);
        $decode = fn (string $part): string => base64_decode(strtr($part, '-_', '+/'));

        $this->assertSame('sheets@test.iam.gserviceaccount.com', json_decode($decode($claims), true)['iss']);
        $this->assertSame(1, openssl_verify("{$header}.{$claims}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
    }

    public function test_spreadsheet_id_is_taken_from_the_link(): void
    {
        Setting::put('google_sheet_url', 'https://docs.google.com/spreadsheets/d/1I1zUgUB08bPYs81hBqgOgJ_PpIMw8y_blsppmpEO_t0/edit?gid=2006214835#gid=2006214835');

        $this->assertSame('1I1zUgUB08bPYs81hBqgOgJ_PpIMw8y_blsppmpEO_t0', GoogleSheetsClient::spreadsheetId());
    }

    /**
     * Тестовий ключ лише для перевірки підпису — він ні до чого не дає доступу.
     * Генерувати на льоту не можна: на Windows PHP без openssl.cnf ключ не створює.
     */
    private function configure(): void
    {
        $privateKey = (string) file_get_contents(base_path('tests/Fixtures/google-test-key.pem'));
        $this->publicKey = openssl_pkey_get_details(openssl_pkey_get_private($privateKey))['key'];

        $path = tempnam(sys_get_temp_dir(), 'gsa');
        file_put_contents($path, json_encode([
            'client_email' => 'sheets@test.iam.gserviceaccount.com',
            'private_key' => $privateKey,
        ]));

        config(['services.google_sheets.credentials' => $path]);
        Setting::put('google_sheet_url', 'https://docs.google.com/spreadsheets/d/'.self::SHEET_ID.'/edit');
        Cache::flush();
    }

    /** @param  array<int, array{sheetId: int, title: string, index: int}>  $sheets */
    private function fakeGoogle(array $sheets): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'sheets.googleapis.com/v4/spreadsheets/*:batchUpdate' => Http::response(['replies' => [['addSheet' => ['properties' => ['sheetId' => 42]]]]]),
            'sheets.googleapis.com/v4/spreadsheets/*/values/*' => Http::response([]),
            'sheets.googleapis.com/v4/spreadsheets/*' => Http::response([
                'properties' => ['title' => 'Замовлення'],
                'sheets' => array_map(fn (array $sheet): array => ['properties' => $sheet], $sheets),
            ]),
        ]);
    }

    private function order(): Order
    {
        $order = Order::create([
            'number' => 'ЗМ-20260901-0001',
            'student_id' => $this->student->id,
            'school_class_id' => $this->student->school_class_id,
            'placed_at' => now(),
        ]);

        $order->lines()->create([
            'student_id' => $this->student->id,
            'supplier_id' => $this->supplier->id,
            'service_date' => self::SERVICE_DATE,
            'dish_id' => null,
            'dish_name' => 'Борщ',
            'section_type' => MenuSectionType::Choice,
            'section_title' => 'Перше',
            'quantity' => 1,
            'unit_price' => 45,
        ]);

        return $order->load('lines');
    }
}
