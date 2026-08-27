<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Services\Import\StudentImportRow;
use App\Services\Import\StudentImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use RefreshDatabase;

    private StudentImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(StudentImportService::class);
    }

    public function test_rows_are_parsed_with_class_and_login(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Клас;Логін;E-mail',
            'Іваненко Марія;5-А;ivanenko.mariia;mama@example.com',
            'Петренко Іван;7 Б;petrenko.ivan;',
        ]));

        $this->assertCount(2, $rows);

        $first = $rows->first();
        $this->assertSame('Іваненко Марія', $first->fullName);
        $this->assertSame(5, $first->grade);
        $this->assertSame('А', $first->letter);
        $this->assertSame('mama@example.com', $first->email);
        $this->assertSame(StudentImportRow::ACTION_CREATE, $first->action);

        // «7 Б» без дефіса теж має розпізнатися.
        $this->assertSame('7-Б', $rows[1]->className());
    }

    public function test_existing_login_is_marked_for_update_not_create(): void
    {
        $this->existingStudent('ivanenko.mariia', 'Іваненко Марія');

        $rows = $this->service->parse($this->csv([
            'ПІБ;Клас;Логін',
            'Іваненко Марія;6-А;ivanenko.mariia',
        ]));

        $this->assertSame(StudentImportRow::ACTION_UPDATE, $rows->first()->action);
    }

    public function test_duplicate_login_inside_the_file_is_reported(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Клас;Логін',
            'Іваненко Марія;5-А;ivanenko',
            'Іваненко Марина;6-Б;ivanenko',
        ]));

        $this->assertTrue($rows[0]->isValid());
        $this->assertFalse($rows[1]->isValid());
        $this->assertStringContainsString('повторюється', $rows[1]->error);
    }

    public function test_broken_rows_are_reported_with_reasons(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Клас;Логін;E-mail',
            ';5-А;bez.piba;',
            'Без логіна;5-А;;',
            'Поганий клас;99;pohanyi.klas;',
            'Поганий email;5-А;pohanyi.email;не-пошта',
        ]));

        $this->assertSame('Порожнє ПІБ', $rows[0]->error);
        $this->assertSame('Порожній логін', $rows[1]->error);
        $this->assertStringContainsString('Не розпізнано клас', $rows[2]->error);
        $this->assertStringContainsString('Некоректний e-mail', $rows[3]->error);
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
            'ПІБ;Клас;Логін',
            'Хтось;5-А;smachno',
        ]));

        $this->assertFalse($rows->first()->isValid());
        $this->assertStringContainsString('службовим акаунтом', $rows->first()->error);
    }

    public function test_apply_creates_students_and_returns_credentials(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Клас;Логін',
            'Іваненко Марія;5-А;ivanenko.mariia',
            'Петренко Іван;5-А;petrenko.ivan',
        ]));

        $result = $this->service->apply($rows, null, 'spysok.csv', 2026);

        $this->assertSame(2, $result['batch']->created_count);
        $this->assertCount(2, $result['credentials']);
        $this->assertSame(2, Student::query()->count());

        // Клас створено автоматично.
        $class = SchoolClass::query()->firstOrFail();
        $this->assertSame(5, $class->grade);
        $this->assertSame(2026, $class->academic_year);

        // Виданий пароль справді працює.
        $credentials = $result['credentials']->first();
        $user = User::query()->where('login', $credentials['login'])->firstOrFail();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($credentials['password'], $user->password));
    }

    public function test_repeated_import_updates_and_does_not_duplicate(): void
    {
        $student = $this->existingStudent('ivanenko.mariia', 'Іваненко Марія');
        $originalPassword = $student->user->password;

        $rows = $this->service->parse($this->csv([
            'ПІБ;Клас;Логін;E-mail',
            'Іваненко Марія-Анна;6-Б;ivanenko.mariia;mama@example.com',
        ]));

        $result = $this->service->apply($rows, null, 'spysok.csv', 2026);

        $this->assertSame(0, $result['batch']->created_count);
        $this->assertSame(1, $result['batch']->updated_count);
        $this->assertSame(1, Student::query()->count());

        $student->refresh();

        $this->assertSame('Іваненко Марія-Анна', $student->full_name);
        $this->assertSame('6-Б', $student->schoolClass->title);
        $this->assertSame('mama@example.com', $student->user->email);
        // Пароль наявного учня імпорт не змінює.
        $this->assertSame($originalPassword, $student->user->fresh()->password);
    }

    public function test_broken_rows_are_skipped_but_recorded_in_the_batch(): void
    {
        $rows = $this->service->parse($this->csv([
            'ПІБ;Клас;Логін',
            'Іваненко Марія;5-А;ivanenko.mariia',
            ';5-А;bez.piba',
        ]));

        $result = $this->service->apply($rows, null, 'spysok.csv', 2026);

        $this->assertSame(1, $result['batch']->created_count);
        $this->assertSame(1, $result['batch']->skipped_count);
        $this->assertSame(1, Student::query()->count());
        $this->assertSame('Порожнє ПІБ', $result['batch']->errors[0]['error']);
    }

    public function test_file_without_required_headers_yields_nothing(): void
    {
        $rows = $this->service->parse($this->csv([
            'Прізвище учня;Група',
            'Іваненко Марія;5-А',
        ]));

        $this->assertTrue($rows->isEmpty());
    }

    /** @param array<int, string> $lines */
    private function csv(array $lines): string
    {
        Storage::fake('local');

        $path = Storage::disk('local')->path('import-test.csv');

        file_put_contents($path, implode("\n", $lines));

        return $path;
    }

    private function existingStudent(string $login, string $name): Student
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
            'school_class_id' => SchoolClass::query()->firstOrCreate([
                'academic_year' => 2026, 'grade' => 5, 'letter' => 'А',
            ])->id,
        ]);
    }
}
