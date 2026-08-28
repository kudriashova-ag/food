<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Filament\Pages\ImportStudents;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Сторінка імпорту, а не сам сервіс.
 *
 * Форма тут не зберігається, тож вибраний файл лишається тимчасовим —
 * саме на цьому імпорт раніше й спинявся: прев'ю було порожнє, кнопки не було.
 */
class ImportStudentsPageTest extends TestCase
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
        Livewire::test(ImportStudents::class)
            ->set('data.file', $this->file())
            ->assertOk()
            ->assertSee('Іваненко Марія')
            ->assertSee('Петренко Іван');
    }

    public function test_summary_counts_the_rows_from_the_uploaded_file(): void
    {
        $page = Livewire::test(ImportStudents::class)
            ->set('data.file', $this->file());

        $this->assertSame(
            ['create' => 2, 'update' => 0, 'error' => 0, 'total' => 2],
            $page->instance()->getSummary(),
        );
    }

    public function test_import_writes_the_students(): void
    {
        Livewire::test(ImportStudents::class)
            ->set('data.academic_year', 2026)
            ->set('data.file', $this->file())
            ->callAction('apply');

        $this->assertSame(2, Student::query()->count());
        $this->assertNotNull(User::query()->where('login', 'ivanenko.mariia')->first());
    }

    public function test_password_from_the_file_reaches_the_student(): void
    {
        Livewire::test(ImportStudents::class)
            ->set('data.academic_year', 2026)
            ->set('data.file', $this->file())
            ->callAction('apply');

        $user = User::query()->where('login', 'ivanenko.mariia')->firstOrFail();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Kvitka2026', $user->password));
    }

    private function file(): UploadedFile
    {
        $csv = implode("\n", [
            'ПІБ;Клас;Логін;E-mail;Пароль',
            'Іваненко Марія;5-А;ivanenko.mariia;mariia@example.com;Kvitka2026',
            'Петренко Іван;5-А;petrenko.ivan;;',
        ]);

        return UploadedFile::fake()->createWithContent('spysok.csv', $csv);
    }
}
