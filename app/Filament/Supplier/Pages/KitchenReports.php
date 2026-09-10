<?php

namespace App\Filament\Supplier\Pages;

use App\Exports\KitchenReportExport;
use App\Exports\SupplierOrdersWeekExport;
use App\Models\Supplier;
use App\Services\Reports\KitchenReportService;
use App\Services\Reports\SupplierOrdersWeekService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class KitchenReports extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.supplier.pages.kitchen-reports';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'Звіти для кухні';

    protected static ?string $title = 'Звіти для кухні';

    protected static ?int $navigationSort = 6;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['date' => today()->toDateString()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        DatePicker::make('date')
                            ->label('Дата харчування')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->required()
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    public function getDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->data['date'] ?? today());
    }

    /** @return array{dishes: \Illuminate\Support\Collection, positions: int, students: int} */
    public function getSummary(): array
    {
        return app(KitchenReportService::class)->dailySummary($this->supplier(), $this->getDate());
    }

    public function getHandoutList(): \Illuminate\Support\Collection
    {
        return app(KitchenReportService::class)->handoutList($this->supplier(), $this->getDate());
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->weekOrdersExportAction(),

            Action::make('excel')
                ->label('Експорт у Excel')
                ->icon('heroicon-o-table-cells')
                ->action(fn (): BinaryFileResponse => $this->downloadExcel()),

            Action::make('pdf')
                ->label('Друк / PDF')
                ->icon('heroicon-o-printer')
                ->action(fn (): Response => $this->downloadPdf()),
        ];
    }

    /** Тижневий звіт замовлень: хто що замовив, по днях і секціях меню. */
    private function weekOrdersExportAction(): Action
    {
        return Action::make('weekOrders')
            ->label('Замовлення за тиждень')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->modalHeading('Експорт замовлень за тиждень')
            ->modalDescription('За замовчуванням — наступний робочий тиждень, дати можна змінити.')
            ->modalSubmitActionLabel('Завантажити')
            ->schema([
                DatePicker::make('from')
                    ->label('З дати')
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->required()
                    ->default(static::nextWeekStart()->toDateString()),

                DatePicker::make('to')
                    ->label('По дату')
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->required()
                    ->afterOrEqual('from')
                    ->default(static::nextWeekEnd()->toDateString()),
            ])
            ->action(function (array $data): BinaryFileResponse {
                $from = CarbonImmutable::parse($data['from'])->startOfDay();
                $to = CarbonImmutable::parse($data['to'])->startOfDay();

                $report = app(SupplierOrdersWeekService::class)->build($this->supplier(), $from, $to);

                $export = new SupplierOrdersWeekExport($report['columns'], $report['rows']);

                $path = sprintf('exports/%s.xlsx', Str::uuid());
                $export->store($path, 'local');

                return response()->download(
                    Storage::disk('local')->path($path),
                    sprintf('zamovlennia-%s-%s-%s.xlsx', $this->supplier()->slug, $from->toDateString(), $to->toDateString()),
                )->deleteFileAfterSend();
            });
    }

    /** Понеділок наступного тижня — явно, без покладання на дефолтну локаль. */
    private static function nextWeekStart(): CarbonInterface
    {
        return today()->addWeek()->startOfWeek(CarbonInterface::MONDAY);
    }

    private static function nextWeekEnd(): CarbonInterface
    {
        return static::nextWeekStart()->addDays(4);
    }

    private function downloadExcel(): BinaryFileResponse
    {
        $export = new KitchenReportExport(
            $this->supplier(),
            $this->getDate(),
            app(KitchenReportService::class),
        );

        return $export->download($this->fileName('xlsx'));
    }

    private function downloadPdf(): Response
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('reports.kitchen-pdf', [
            'supplier' => $this->supplier(),
            'date' => $this->getDate(),
            'summary' => $this->getSummary(),
            'classes' => $this->getHandoutList(),
        ]);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $this->fileName('pdf'),
        );
    }

    private function fileName(string $extension): string
    {
        return sprintf('kuhnia-%s-%s.%s', $this->supplier()->slug, $this->getDate()->format('Y-m-d'), $extension);
    }

    private function supplier(): Supplier
    {
        return auth()->user()->supplier;
    }
}
