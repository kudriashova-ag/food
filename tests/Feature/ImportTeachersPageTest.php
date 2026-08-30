<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ImportTeachers;
use App\Models\Student;
use App\Models\User;
use App\Services\Import\TeacherImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Сторінка імпорту вчителів (аналог ImportStudentsPageTest). */
class ImportTeachersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->actingAs(User::create([
            'name' => 'Адміністратор',
            'login' => 'admin',
            'email' => 'admin@school.test',
            'password' => 'secret',
            'role' => UserRole::Admin,
        ]));
    }

    public function test_uploaded_file_is_previewed_right_away(): void
    {
        Livewire::test(ImportTeachers::class)
            ->set('data.file', $this->file())
            ->assertOk()
            ->assertSee('Коваленко Ольга')
            ->assertSee('Сидоренко Петро');
    }

    public function test_summary_counts_the_rows_from_the_uploaded_file(): void
    {
        $page = Livewire::test(ImportTeachers::class)
            ->set('data.file', $this->file());

        $this->assertSame(
            ['create' => 2, 'update' => 0, 'error' => 0, 'total' => 2],
            $page->instance()->getSummary(),
        );
    }

    public function test_import_writes_teachers_with_the_fixed_password(): void
    {
        Livewire::test(ImportTeachers::class)
            ->set('data.file', $this->file())
            ->callAction('apply');

        $this->assertSame(2, Student::query()->count());

        $user = User::query()->where('login', 'kovalenko.olha')->firstOrFail();

        $this->assertTrue(Hash::check(TeacherImportService::DEFAULT_PASSWORD, $user->password));
        $this->assertTrue($user->must_change_password);
    }

    private function file(): UploadedFile
    {
        $csv = implode("\n", [
            'ПІБ;Логін;E-mail',
            'Коваленко Ольга;kovalenko.olha;olha@example.com',
            'Сидоренко Петро;sydorenko.petro;',
        ]);

        return UploadedFile::fake()->createWithContent('vchyteli.csv', $csv);
    }
}
