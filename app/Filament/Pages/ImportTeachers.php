<?php

namespace App\Filament\Pages;

use App\Exports\TeacherCredentialsExport;
use App\Services\Import\TeacherImportRow;
use App\Services\Import\TeacherImportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Імпорт вчителів — той самий двоетапний підхід, що й для учнів
 * (див. ImportStudents), але простіший: без класу, і всі нові акаунти
 * отримують той самий тимчасовий пароль замість пароля з файлу.
 */
class ImportTeachers extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.import-teachers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $navigationLabel = 'Імпорт вчителів';

    protected static ?string $title = 'Імпорт списку вчителів';

    protected static ?int $navigationSort = 7;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Шлях до згенерованого файлу з логінами й тимчасовим паролем. */
    public ?string $credentialsPath = null;

    public ?string $lastResult = null;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Файл списку')
                    ->description(sprintf(
                        'Excel або CSV з колонками: ПІБ, Логін і E-mail (останній — необов\'язковий). '.
                        'Усім новим вчителям видається тимчасовий пароль «%s» — при першому вході систему обов\'язково попросить його змінити.',
                        TeacherImportService::DEFAULT_PASSWORD,
                    ))
                    ->schema([
                        FileUpload::make('file')
                            ->label('Файл')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                                'text/plain',
                            ])
                            ->disk('local')
                            ->directory('imports')
                            ->maxSize(10240)
                            ->live()
                            ->columnSpanFull(),
                    ]),
            ])
            ->statePath('data');
    }

    public function hasFile(): bool
    {
        return filled($this->filePath());
    }

    /** @return Collection<int, TeacherImportRow> */
    public function getRows(): Collection
    {
        if (! $this->hasFile()) {
            return collect();
        }

        return app(TeacherImportService::class)->parse($this->filePath());
    }

    /** @return array{create: int, update: int, error: int, total: int} */
    public function getSummary(): array
    {
        return app(TeacherImportService::class)->summarize($this->getRows());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('apply')
                ->label('Записати в базу')
                ->icon('heroicon-o-check')
                ->requiresConfirmation()
                ->modalHeading('Підтвердження імпорту')
                ->modalDescription(fn (): string => $this->confirmationText())
                ->modalSubmitActionLabel('Записати')
                ->visible(fn (): bool => $this->hasFile() && $this->getSummary()['total'] > 0)
                ->action(fn () => $this->apply()),

            Action::make('downloadCredentials')
                ->label('Завантажити логіни й паролі')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->credentialsPath !== null)
                ->action(fn (): BinaryFileResponse => response()->download(
                    Storage::disk('local')->path($this->credentialsPath),
                    'lohiny-ta-paroli-vchyteliv.xlsx',
                )),
        ];
    }

    private function apply(): void
    {
        $rows = $this->getRows();

        $result = app(TeacherImportService::class)->apply(
            rows: $rows,
            actor: auth()->user(),
            filename: basename((string) $this->filePath()),
        );

        $batch = $result['batch'];
        $credentials = $result['credentials'];

        $this->lastResult = sprintf(
            'Створено: %d, оновлено: %d, пропущено через помилки: %d.',
            $batch->created_count,
            $batch->updated_count,
            $batch->skipped_count,
        );

        $this->credentialsPath = $credentials->isNotEmpty()
            ? $this->storeCredentials($credentials, $batch->id)
            : null;

        $path = $this->filePath();

        if ($path !== null && is_file($path)) {
            @unlink($path);
        }

        $this->data['file'] = null;

        Notification::make()
            ->title('Імпорт виконано')
            ->body($this->lastResult)
            ->success()
            ->persistent()
            ->send();
    }

    private function storeCredentials(Collection $credentials, int $batchId): string
    {
        $path = "imports/teacher-credentials-{$batchId}.xlsx";

        (new TeacherCredentialsExport($credentials))->store($path, 'local');

        return $path;
    }

    private function confirmationText(): string
    {
        $summary = $this->getSummary();

        return sprintf(
            'Буде створено %d, оновлено %d. Рядків з помилками: %d — вони будуть пропущені.',
            $summary['create'],
            $summary['update'],
            $summary['error'],
        );
    }

    /**
     * Абсолютний шлях до вибраного файлу.
     *
     * Форма тут не зберігається, тож FileUpload не переносить файл на свій диск —
     * поки сторінка відкрита, це TemporaryUploadedFile у сховищі Livewire.
     */
    private function filePath(): ?string
    {
        $file = $this->data['file'] ?? null;

        if (is_array($file)) {
            $file = reset($file) ?: null;
        }

        if ($file instanceof TemporaryUploadedFile) {
            return $file->getRealPath() ?: null;
        }

        if (! is_string($file) || $file === '') {
            return null;
        }

        $path = Storage::disk('local')->path($file);

        return is_file($path) ? $path : null;
    }
}
