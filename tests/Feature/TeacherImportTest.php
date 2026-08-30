<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Student;
use App\Models\User;
use App\Services\Import\TeacherImportRow;
use App\Services\Import\TeacherImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeacherImportTest extends TestCase
{
    use RefreshDatabase;

    private TeacherImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TeacherImportService::class);
    }

    public function test_rows_are_parsed_without_a_class(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Логін;E-mail',
            'Коваленко Ольга;kovalenko.olha;olha@example.com',
            'Сидоренко Петро;sydorenko.petro;',
        ]));

        $this->assertCount(2, $rows);

        $first = $rows->first();
        $this->assertSame('Коваленко Ольга', $first->fullName);
        $this->assertSame('olha@example.com', $first->email);
        $this->assertSame(TeacherImportRow::ACTION_CREATE, $first->action);
    }

    public function test_existing_login_is_marked_for_update_not_create(): void
    {
        $this->existingTeacher('kovalenko.olha', 'Коваленко Ольга');

        $rows = $this->service->parse($this->csv([
            'ПІБ;Логін',
            'Коваленко Ольга;kovalenko.olha',
        ]));

        $this->assertSame(TeacherImportRow::ACTION_UPDATE, $rows->first()->action);
    }

    public function test_duplicate_login_inside_the_file_is_reported(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Логін',
            'Коваленко Ольга;kovalenko',
            'Коваль Оксана;kovalenko',
        ]));

        $this->assertTrue($rows[0]->isValid());
        $this->assertFalse($rows[1]->isValid());
        $this->assertStringContainsString('повторюється', $rows[1]->error);
    }

    public function test_broken_rows_are_reported_with_reasons(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Логін;E-mail',
            ';bez.piba;',
            'Без логіна;;',
            'Поганий email;pohanyi.email;не-пошта',
        ]));

        $this->assertSame('Порожнє ПІБ', $rows[0]->error);
        $this->assertSame('Порожній логін', $rows[1]->error);
        $this->assertStringContainsString('Некоректний e-mail', $rows[2]->error);
    }

    public function test_service_login_cannot_be_overwritten_by_import(): void
    {
        User::create([
            'name' => 'Постачальник',
            'login' => 'smachno',
            'email' => 'smachno@school.local',
            'password' => 'secret',
            'role' => UserRole::Supplier,
        ]);

        $rows = $this->service->parse($this->csv([
            'ПІБ;Логін',
            'Хтось;smachno',
        ]));

        $this->assertFalse($rows->first()->isValid());
        $this->assertStringContainsString('службовим акаунтом', $rows->first()->error);
    }

    public function test_apply_creates_teachers_with_fixed_password_and_must_change_flag(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Логін',
            'Коваленко Ольга;kovalenko.olha',
            'Сидоренко Петро;sydorenko.petro',
        ]));

        $result = $this->service->apply($rows, null, 'vchyteli.csv');

        $this->assertSame(2, $result['batch']->created_count);
        $this->assertCount(2, $result['credentials']);
        $this->assertSame(2, Student::query()->count());

        $user = User::query()->where('login', 'kovalenko.olha')->firstOrFail();

        // Усім видається той самий фіксований пароль.
        $this->assertTrue(Hash::check(TeacherImportService::DEFAULT_PASSWORD, $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame(TeacherImportService::DEFAULT_PASSWORD, $result['credentials']->first()['password']);

        // Профіль — Student без класу, зі згодою, наданою автоматично.
        $student = Student::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($student->school_class_id);
        $this->assertNotNull($student->consent_at);
    }

    public function test_repeated_import_updates_and_does_not_touch_password(): void
    {
        $teacher = $this->existingTeacher('kovalenko.olha', 'Коваленко Ольга');
        $originalPassword = $teacher->user->password;

        $rows = $this->service->parse($this->csv([
            'ПІБ;Логін;E-mail',
            'Коваленко Ольга Іванівна;kovalenko.olha;olha@example.com',
        ]));

        $result = $this->service->apply($rows, null, 'vchyteli.csv');

        $this->assertSame(0, $result['batch']->created_count);
        $this->assertSame(1, $result['batch']->updated_count);
        $this->assertSame(1, Student::query()->count());
        $this->assertCount(0, $result['credentials']);

        $teacher->refresh();

        $this->assertSame('Коваленко Ольга Іванівна', $teacher->full_name);
        $this->assertSame('olha@example.com', $teacher->user->email);
        // Пароль наявного вчителя повторний імпорт не чіпає.
        $this->assertSame($originalPassword, $teacher->user->fresh()->password);
    }

    public function test_broken_rows_are_skipped_but_recorded_in_the_batch(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Логін',
            'Коваленко Ольга;kovalenko.olha',
            ';bez.piba',
        ]));

        $result = $this->service->apply($rows, null, 'vchyteli.csv');

        $this->assertSame(1, $result['batch']->created_count);
        $this->assertSame(1, $result['batch']->skipped_count);
        $this->assertSame(1, Student::query()->count());
        $this->assertSame('Порожнє ПІБ', $result['batch']->errors[0]['error']);
    }

    public function test_file_without_required_headers_yields_nothing(): void
    {
        $rows = $this->service->parse($this->csv([
            'Прізвище;Група',
            'Коваленко Ольга;10',
        ]));

        $this->assertTrue($rows->isEmpty());
    }

    /** @param array<int, string> $lines */
    private function csv(array $lines): string
    {
        Storage::fake('local');

        $path = Storage::disk('local')->path('teacher-import-test.csv');

        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    private function existingTeacher(string $login, string $name): Student
    {
        $user = User::create([
            'name' => $name,
            'login' => $login,
            'password' => 'secret',
            'role' => UserRole::Student,
        ]);

        return Student::create([
            'user_id' => $user->id,
            'full_name' => $name,
            'school_class_id' => null,
            'consent_at' => now(),
        ]);
    }
}
