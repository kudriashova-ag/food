<?php

namespace App\Filament\Pages;

use App\Exports\StudentCredentialsExport;
use App\Services\Import\StudentImportRow;
use App\Services\Import\StudentImportService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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
 * Двоетапний імпорт: спершу показуємо, що саме зміниться, і лише після
 * підтвердження пишемо в базу. Звірка йде за логіном (ТЗ, п. 3.1).
 */
class ImportStudents extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.import-students';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Імпорт учнів';

    protected static ?string $title = 'Імпорт списку учнів';

    protected static ?int $navigationSort = 6;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** Шлях до згенерованого файлу з логінами й паролями. */
    public ?string $credentialsPath = null;

    public ?string $lastResult = null;

    public function mount(): void
    {
        $this->form->fill([
            'academic_year' => now()->month >= 8 ? now()->year : now()->year - 1,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Файл списку')
                    ->description('Excel або CSV з колонками: ПІБ, Клас, Логін, E-mail і Пароль (два останні — необов\'язкові). Без колонки «Пароль» він згенерується автоматично.')
                    ->columns(2)
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

                        Select::make('academic_year')
                            ->label('Навчальний рік')
                            ->options(fn (): array => collect(range(now()->year - 1, now()->year + 1))
                                ->mapWithKeys(fn (int $year): array => [$year => $year.'/'.substr((string) ($year + 1), 2)])
                                ->all())
                            ->required()
                            ->helperText('Класи з файлу створяться в цьому році.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function hasFile(): bool
    {
        return filled($this->filePath());
    }

    /** @return Collection<int, StudentImportRow> */
    public function getRows(): Collection
    {
        if (! $this->hasFile()) {
            return collect();
        }

        return app(StudentImportService::class)->parse($this->filePath());
    }

    /** @return array{create: int, update: int, error: int, total: int} */
    public function getSummary(): array
    {
        return app(StudentImportService::class)->summarize($this->getRows());
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
                    'lohiny-ta-paroli.xlsx',
                )),
        ];
    }

    private function apply(): void
    {
        $rows = $this->getRows();

        $result = app(StudentImportService::class)->apply(
            rows: $rows,
            actor: auth()->user(),
            filename: basename((string) $this->filePath()),
            academicYear: (int) $this->data['academic_year'],
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

        // Файл більше не потрібен: повторний імпорт починається з нового завантаження.
        // Шлях абсолютний (файл лежить у сховищі Livewire), тому прибираємо напряму.
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
        $path = "imports/credentials-{$batchId}.xlsx";

        (new StudentCredentialsExport($credentials))->store($path, 'local');

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
     * Читаємо звідти, а не з imports/, куди файл так і не потрапляє.
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
