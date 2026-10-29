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

    /** Що повертає «Замовлення» з includeGridData: рядки клітинок {formattedValue, userEnteredFormat}. */
    private array $grid = [];

    /** Те саме для вкладки «Оплата». */
    private array $paymentGrid = [];

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
            && data_get($request->data(), 'values.3.2') === 'Іваненко Марія'
            && data_get($request->data(), 'values.3.0') === 's'.$this->student->id
            && data_get($request->data(), 'values.0.4') === self::SERVICE_DATE.'|Перше');

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), ':clear') && $request->body() === '{}');
        Http::assertSent(fn (Request $request): bool => str_contains(json_encode($request->data()), 'SOLID_THICK'));
    }

    public function test_cancelled_dish_stays_in_the_cell_on_a_red_background(): void
    {
        $this->connect();
        $this->order(status: OrderLineStatus::Cancelled);
        $this->fakeGoogle();

        app(OrdersWorkbook::class)->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && data_get($request->data(), 'values.3.4') === 'Борщ');

        Http::assertSent(function (Request $request): bool {
            return collect(data_get($request->data(), 'requests', []))->contains(
                fn (array $r): bool => data_get($r, 'repeatCell.fields') === 'userEnteredFormat.backgroundColor'
                    && data_get($r, 'repeatCell.range.startRowIndex') === 3
                    && data_get($r, 'repeatCell.range.startColumnIndex') === 4
                    && round(data_get($r, 'repeatCell.cell.userEnteredFormat.backgroundColor.red'), 2) === 0.92,
            );
        });
    }

    public function test_rows_go_classes_then_college_courses_then_teachers(): void
    {
        $this->connect();
        $this->fakeGoogle();

        $this->pupilOrder('Яременко Олег', 7, 'Б');
        $this->pupilOrder('Бойко Анна', 2, 'Курс');
        $this->pupilOrder('Андрієнко Ігор', 1, 'Курс');
        $this->pupilOrder('Коваленко Ольга', null, null);
        $this->pupilOrder('Абрамова Ірина', 5, 'А');
        $this->order();   // Іваненко Марія, 5-А

        app(OrdersWorkbook::class)->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $names = collect(array_slice(data_get($request->data(), 'values'), 3))->pluck(2)->all();

            return $names === ['Абрамова Ірина', 'Іваненко Марія', 'Яременко Олег', 'Андрієнко Ігор', 'Бойко Анна', 'Коваленко Ольга'];
        });

        // Службові ключі приховано.
        Http::assertSent(fn (Request $request): bool => collect(data_get($request->data(), 'requests', []))->contains(
            fn (array $r): bool => data_get($r, 'updateDimensionProperties.range.dimension') === 'COLUMNS'
                && data_get($r, 'updateDimensionProperties.properties.hiddenByUser') === true,
        ));
    }

    public function test_manual_colors_follow_the_pupil_when_rows_shift(): void
    {
        $this->connect();
        $this->fakeGoogle();

        $this->order();   // Іваненко Марія, 5-А
        $key = 's'.$this->student->id;
        $yellow = ['red' => 1, 'green' => 0.9490196, 'blue' => 0.8];
        $cancelledRed = ['red' => 0.91764706, 'green' => 0.6, 'blue' => 0.6];

        // Таблиця, яку бачила адміністраторка: Марія в першому рядку даних,
        // клітинка «Перше» пофарбована вручну, а ПІБ — нашим червоним (не враховуємо).
        $this->grid = [
            ['values' => $this->cells(['', '_num', '_name', '_class', self::SERVICE_DATE.'|Перше'])],
            ['values' => []],
            ['values' => []],
            ['values' => [
                ['formattedValue' => $key],
                ['formattedValue' => '1'],
                ['formattedValue' => 'Іваненко Марія', 'userEnteredFormat' => ['backgroundColor' => $cancelledRed]],
                ['formattedValue' => '5-А'],
                ['formattedValue' => 'Борщ', 'userEnteredFormat' => ['backgroundColor' => $yellow]],
            ]],
        ];

        // Новий учень того ж класу стає вище за алфавітом — Марія зсувається на рядок нижче.
        $this->pupilOrder('Абрамова Ірина', 5, 'А');

        app(OrdersWorkbook::class)->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));

        $colored = collect(Http::recorded())
            ->flatMap(fn (array $pair): array => data_get($pair[0]->data(), 'requests', []))
            ->filter(fn (array $r): bool => data_get($r, 'repeatCell.fields') === 'userEnteredFormat.backgroundColor')
            ->map(fn (array $r): array => [
                data_get($r, 'repeatCell.range.startRowIndex'),
                data_get($r, 'repeatCell.range.startColumnIndex'),
                data_get($r, 'repeatCell.cell.userEnteredFormat.backgroundColor'),
            ])
            ->values();

        // Жовтий — у рядку Марії (тепер 5-й, індекс 4) і колонці «Перше» (індекс 4).
        $this->assertContains([4, 4, $yellow], $colored->all());
        // На старе місце (індекс 3 — тепер Абрамова) колір не переходить, наш червоний не відновлюється.
        $this->assertNotContains([3, 4, $yellow], $colored->all());
        $this->assertFalse($colored->contains(fn (array $c): bool => $c[2] === $cancelledRed));
    }

    public function test_payment_tab_sums_active_dishes_by_day(): void
    {
        $this->connect();
        $this->fakeGoogle();

        $order = $this->order();   // Борщ 45 на 08.09
        $order->lines()->create([
            'student_id' => $this->student->id, 'supplier_id' => $this->supplier->id, 'service_date' => '2026-09-09',
            'dish_name' => 'Комплекс №1: Салат, Котлета', 'section_type' => MenuSectionType::Complex, 'section_title' => 'Комплекс',
            'quantity' => 1, 'unit_price' => 70,
        ]);
        // Скасоване до сплати не йде.
        $order->lines()->create([
            'student_id' => $this->student->id, 'supplier_id' => $this->supplier->id, 'service_date' => '2026-09-09',
            'dish_name' => 'Компот', 'section_type' => MenuSectionType::Extra, 'section_title' => 'Напій',
            'quantity' => 1, 'unit_price' => 30, 'status' => OrderLineStatus::Cancelled,
        ]);

        app(OrdersWorkbook::class)->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));

        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'requests.0.addSheet.properties.title') === 'Оплата'
            && data_get($request->data(), 'requests.0.addSheet.properties.index') === 1);

        $values = $this->paymentValues();

        $this->assertSame(['', '_num', '_name', '_class', 'day:2026-09-08', 'day:2026-09-09', '_total', '_debt'], $values[0]);
        $this->assertSame(['', '№', "Прізвище, ім'я", 'Клас', '08.09.2026', '09.09.2026', 'Всього до сплати', 'Борг'], $values[1]);
        $this->assertSame(['s'.$this->student->id, 1, 'Іваненко Марія', '5-А', 45, 70, 115, ''], $values[2]);
    }

    public function test_payment_debt_and_colors_stay_with_the_pupil(): void
    {
        $this->connect();
        $this->fakeGoogle();

        $this->order();   // Іваненко Марія, 5-А
        $green = ['red' => 0, 'green' => 1, 'blue' => 0];

        // Петренко цього тижня вже нічого не замовляє, але борг за ним записано.
        $debtorUser = User::create(['name' => 'Петренко Олег', 'login' => 'petrenko', 'password' => 'secret', 'role' => UserRole::Student]);
        $debtor = Student::create([
            'user_id' => $debtorUser->id,
            'full_name' => 'Петренко Олег',
            'school_class_id' => SchoolClass::create(['grade' => 6, 'letter' => 'Б', 'academic_year' => 2026])->id,
        ]);

        $this->paymentGrid = [
            ['values' => $this->cells(['', '_num', '_name', '_class', 'day:'.self::SERVICE_DATE, '_total', '_debt'])],
            ['values' => []],
            ['values' => [
                ['formattedValue' => 's'.$this->student->id], [], [], [], [],
                ['formattedValue' => '45', 'userEnteredValue' => ['numberValue' => 45], 'userEnteredFormat' => ['backgroundColor' => $green]],
                ['formattedValue' => 'квитанція від 23.10', 'userEnteredValue' => ['stringValue' => 'квитанція від 23.10']],
            ]],
            ['values' => [
                ['formattedValue' => 's'.$debtor->id], [], [], [], [], [],
                ['formattedValue' => '120', 'userEnteredValue' => ['numberValue' => 120]],
            ]],
        ];

        // Абрамова стає першою — Марія зсувається на рядок нижче.
        $this->pupilOrder('Абрамова Ірина', 5, 'А');

        app(OrdersWorkbook::class)->writeWeek($this->supplier, CarbonImmutable::parse(self::SERVICE_DATE));

        $values = $this->paymentValues();

        $this->assertSame(['Абрамова Ірина', 'Іваненко Марія', 'Петренко Олег'], array_column(array_slice($values, 2), 2));
        $this->assertSame('', $values[2][6]);
        $this->assertSame('квитанція від 23.10', $values[3][6]);
        $this->assertSame(['s'.$debtor->id, 3, 'Петренко Олег', '6-Б', 0, 0, 120], $values[4]);

        // Зелений «Всього до сплати» — у новому рядку Марії (індекс 3), колонка _total (1 + 4).
        $colored = collect(Http::recorded())
            ->flatMap(fn (array $pair): array => data_get($pair[0]->data(), 'requests', []))
            ->filter(fn (array $r): bool => data_get($r, 'repeatCell.fields') === 'userEnteredFormat.backgroundColor'
                && data_get($r, 'repeatCell.range.sheetId') === 42)
            ->map(fn (array $r): array => [
                data_get($r, 'repeatCell.range.startRowIndex'),
                data_get($r, 'repeatCell.range.startColumnIndex'),
                data_get($r, 'repeatCell.cell.userEnteredFormat.backgroundColor'),
            ])
            ->values()
            ->all();

        $this->assertSame([[3, 5, $green]], $colored);
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

        // Без index — у кінець, після «Замовлення» й «Оплати».
        Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'requests.0.addSheet.properties.title') === 'Журнал змін'
            && data_get($request->data(), 'requests.0.addSheet.properties.index') === null);
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

            if (str_contains($url, 'includeGridData')) {
                $grid = str_contains(urldecode($url), OrdersWorkbook::PAYMENT_TITLE) ? $this->paymentGrid : $this->grid;

                return Http::response(['sheets' => [['data' => [['rowData' => $grid]]]]]);
            }

            // Нова таблиця: лише «Аркуш1».
            return Http::response(['sheets' => [['properties' => ['sheetId' => 0, 'title' => 'Аркуш1', 'index' => 0]]]]);
        });
    }

    /** Значення, записані у вкладку «Оплата» (останній запис). */
    private function paymentValues(): array
    {
        $put = collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->last(fn (Request $request): bool => $request->method() === 'PUT' && str_contains(urldecode($request->url()), "'Оплата'"));

        $this->assertNotNull($put, 'Вкладку «Оплата» не записано');

        return data_get($put->data(), 'values');
    }

    /** @return array<int, array{formattedValue: string}> */
    private function cells(array $values): array
    {
        return array_map(fn (string $value): array => ['formattedValue' => $value], $values);
    }

    /** Замовлення борщу на SERVICE_DATE від нового учня (без класу — вчитель). */
    private function pupilOrder(string $name, ?int $grade, ?string $letter): void
    {
        $user = User::create(['name' => $name, 'login' => uniqid('u-'), 'password' => 'secret', 'role' => UserRole::Student]);

        $student = Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => $grade === null
                ? null
                : SchoolClass::query()->firstOrCreate(['grade' => $grade, 'letter' => $letter, 'academic_year' => 2026])->id,
        ]);

        $order = Order::create([
            'number' => uniqid('ЗМ-'),
            'student_id' => $student->id,
            'school_class_id' => $student->school_class_id,
            'placed_at' => now(),
        ]);

        $order->lines()->create([
            'student_id' => $student->id,
            'supplier_id' => $this->supplier->id,
            'service_date' => self::SERVICE_DATE,
            'dish_id' => null,
            'dish_name' => 'Борщ',
            'section_type' => MenuSectionType::Choice,
            'section_title' => 'Перше',
            'quantity' => 1,
            'unit_price' => 45,
        ]);
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
