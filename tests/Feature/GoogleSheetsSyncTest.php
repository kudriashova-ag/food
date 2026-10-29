<?php

namespace Tests\Feature;

use App\Enums\MenuSectionType;
use App\Enums\OrderLineStatus;
use App\Enums\UserRole;
use App\Jobs\AppendToGoogleSheetJournal;
use App\Jobs\SyncWeekToGoogleSheet;
use App\Models\GoogleDriveItem;
use App\Models\Order;
use App\Models\SchoolClass;
use App\Models\Setting;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\GoogleSheets\GoogleAuth;
use App\Services\GoogleSheets\OrderSheetSync;
use App\Services\GoogleSheets\OrdersWorkbook;
use App\Services\Orders\CancellationService;
use App\Services\Orders\OrderDeletion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleSheetsSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SERVICE_DATE = '2026-09-08';   // вівторок; тиждень 07.09–11.09

    private Supplier $supplier;

    private Student $student;

    /** Підроблений Google Диск: id → [name, mimeType, parents, trashed]. */
    private array $drive = [];

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

    public function test_nothing_is_queued_until_google_drive_is_connected(): void
    {
        Queue::fake();

        OrderSheetSync::placed($this->order());

        Queue::assertNothingPushed();
    }

    public function test_placed_order_goes_to_the_journal_and_its_week(): void
    {
        $this->connect();
        Queue::fake();

        $order = $this->order();
        OrderSheetSync::placed($order);

        Queue::assertPushed(AppendToGoogleSheetJournal::class, function (AppendToGoogleSheetJournal $job) use ($order): bool {
            $row = $job->rows[0];

            return $job->supplierId === $this->supplier->id
                && $job->weekStart === '2026-09-07'
                && $row[1] === 'Замовлено'
                && $row[2] === $order->number
                && $row[3] === 'Іваненко Марія'
                && $row[4] === '5-А'
                && $row[6] === '08.09.2026'
                && $row[8] === 'Борщ';
        });

        Queue::assertPushed(SyncWeekToGoogleSheet::class, fn (SyncWeekToGoogleSheet $job): bool => $job->supplierId === $this->supplier->id && $job->weekStart === '2026-09-07');
    }

    public function test_cancellation_is_journaled_with_who_and_why(): void
    {
        $this->connect();
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
        $this->connect();
        Queue::fake();

        $order = $this->order();
        OrderDeletion::delete($order);

        $this->assertModelMissing($order);
        Queue::assertPushed(AppendToGoogleSheetJournal::class, fn (AppendToGoogleSheetJournal $job): bool => $job->rows[0][1] === 'Видалено' && $job->rows[0][8] === 'Борщ');
        Queue::assertPushed(SyncWeekToGoogleSheet::class, fn (SyncWeekToGoogleSheet $job): bool => $job->weekStart === '2026-09-07');
    }

    public function test_week_file_is_created_in_food_supplier_folder(): void
    {
        $this->connect();
        $this->order();
        $this->fakeGoogle();

        app(OrdersWorkbook::class)->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));

        $byName = collect($this->drive)->keyBy('name');

        $this->assertSame('application/vnd.google-apps.folder', $byName['Food']['mimeType']);
        $this->assertSame([$byName['Food']['id']], $byName['Смачно']['parents']);
        $this->assertSame('application/vnd.google-apps.spreadsheet', $byName['Смачно з 07.09 до 11.09']['mimeType']);
        $this->assertSame([$byName['Смачно']['id']], $byName['Смачно з 07.09 до 11.09']['parents']);

        // «Аркуш1» нової таблиці перейменовується на «Замовлення».
        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'requests.0.updateSheetProperties.properties.title') === 'Замовлення');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_contains(urldecode($request->url()), "'Замовлення'")
            && data_get($request->data(), 'values.2.1') === 'Іваненко Марія');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':clear') && $request->body() === '{}');
        Http::assertSent(fn (Request $request): bool => str_contains(json_encode($request->data()), 'SOLID_THICK'));
    }

    public function test_cancelled_dish_stays_in_the_cell_on_a_red_background(): void
    {
        $this->connect();
        $this->order(status: OrderLineStatus::Cancelled);
        $this->fakeGoogle();

        app(OrdersWorkbook::class)->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && data_get($request->data(), 'values.2.3') === 'Борщ');

        Http::assertSent(function (Request $request): bool {
            return collect(data_get($request->data(), 'requests', []))->contains(
                fn (array $r): bool => data_get($r, 'repeatCell.fields') === 'userEnteredFormat.backgroundColor'
                    && data_get($r, 'repeatCell.range.startRowIndex') === 2
                    && data_get($r, 'repeatCell.range.startColumnIndex') === 3
                    && round(data_get($r, 'repeatCell.cell.userEnteredFormat.backgroundColor.red'), 2) === 0.92,
            );
        });
    }

    public function test_existing_files_are_reused_and_deleted_ones_recreated(): void
    {
        $this->connect();
        $this->order();
        $this->fakeGoogle();
        $workbook = app(OrdersWorkbook::class);

        $workbook->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));
        $this->assertCount(3, $this->drive);

        $workbook->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));
        $this->assertCount(3, $this->drive, 'Повторне оновлення не має створювати нові файли');

        // Файл тижня видалили з Диска — створиться новий.
        $weekId = GoogleDriveItem::query()->where('key', 'like', 'week:%')->value('drive_id');
        $this->drive[$weekId]['trashed'] = true;

        $workbook->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));
        $this->assertCount(4, $this->drive);
        $this->assertNotSame($weekId, GoogleDriveItem::query()->where('key', 'like', 'week:%')->value('drive_id'));
    }

    public function test_journal_tab_is_added_after_orders_with_a_header(): void
    {
        $this->connect();
        $this->fakeGoogle();

        app(OrdersWorkbook::class)->appendJournal($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE), [['08.09.2026 10:00:00', 'Замовлено']]);

        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'requests.0.addSheet.properties.title') === 'Журнал змін'
            && data_get($request->data(), 'requests.0.addSheet.properties.index') === 1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && data_get($request->data(), 'values.0') === OrdersWorkbook::JOURNAL_HEADER);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':append') && data_get($request->data(), 'values.0.1') === 'Замовлено');
    }

    public function test_connecting_stores_the_refresh_token_encrypted(): void
    {
        $this->clientConfig();

        $idToken = 'x.'.rtrim(strtr(base64_encode(json_encode(['email' => 'school@gmail.com'])), '+/', '-_'), '=').'.y';

        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'access-1', 'expires_in' => 3600, 'refresh_token' => 'refresh-1', 'id_token' => $idToken,
        ])]);

        GoogleAuth::connect('auth-code');

        $this->assertTrue(GoogleAuth::isConnected());
        $this->assertSame('school@gmail.com', GoogleAuth::accountEmail());
        $this->assertNotSame('refresh-1', Setting::get('google_refresh_token'));
        $this->assertSame('refresh-1', Crypt::decryptString(Setting::get('google_refresh_token')));
    }

    public function test_access_token_is_refreshed_once_and_cached(): void
    {
        $this->connect();
        $this->fakeGoogle();

        GoogleAuth::accessToken();
        GoogleAuth::accessToken();

        $refreshes = Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com'));

        $this->assertCount(1, $refreshes);
        $this->assertSame('refresh_token', $refreshes->first()[0]['grant_type']);
        $this->assertSame('refresh-1', $refreshes->first()[0]['refresh_token']);
    }

    public function test_callback_with_a_wrong_state_is_rejected(): void
    {
        $this->clientConfig();
        $admin = User::create(['name' => 'Адміністратор', 'email' => 'admin@test.local', 'password' => 'secret', 'role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->withSession(['google_oauth_state' => 'expected'])
            ->get(route('google.callback', ['state' => 'forged', 'code' => 'x']))
            ->assertRedirect()
            ->assertSessionHas('google_error');

        $this->assertFalse(GoogleAuth::isConnected());
    }

    public function test_only_admin_can_connect_google_drive(): void
    {
        $this->clientConfig();

        $this->actingAs($this->student->user)
            ->get(route('google.connect'))
            ->assertForbidden();
    }

    private function clientConfig(): void
    {
        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'client-secret']);
        Cache::flush();
    }

    private function connect(): void
    {
        $this->clientConfig();

        Setting::put('google_refresh_token', Crypt::encryptString('refresh-1'));
        Setting::put('google_account_email', 'school@gmail.com');
    }

    private function fakeGoogle(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
            }

            if (str_contains($url, 'drive/v3/files')) {
                if ($request->method() === 'POST') {
                    $id = 'drive-'.(count($this->drive) + 1);
                    $this->drive[$id] = ['id' => $id, 'name' => $request['name'], 'mimeType' => $request['mimeType'], 'parents' => $request['parents'] ?? [], 'trashed' => false];

                    return Http::response(['id' => $id]);
                }

                $id = urldecode(basename(parse_url($url, PHP_URL_PATH)));

                return isset($this->drive[$id])
                    ? Http::response($this->drive[$id])
                    : Http::response(['error' => ['code' => 404]], 404);
            }

            if (str_contains($url, ':batchUpdate')) {
                return Http::response(['replies' => [['addSheet' => ['properties' => ['sheetId' => 42]]]]]);
            }

            if (str_contains($url, '/values/')) {
                return Http::response([]);
            }

            // Нова таблиця: лише «Аркуш1».
            return Http::response(['sheets' => [['properties' => ['sheetId' => 0, 'title' => 'Аркуш1', 'index' => 0]]]]);
        });
    }

    private function order(OrderLineStatus $status = OrderLineStatus::Active): Order
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
            'status' => $status,
        ]);

        return $order->load('lines');
    }
}
