<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\Reports\SchoolSummaryService;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class SchoolSummary extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.school-summary';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?string $navigationLabel = 'Зведення по школі';

    protected static ?string $title = 'Зведення по школі';

    protected static ?int $navigationSort = 1;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'from' => today()->startOfWeek()->toDateString(),
            'to' => today()->startOfWeek()->addDays(4)->toDateString(),
            'missing_date' => today()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Період')
                    ->columns(3)
                    ->schema([
                        DatePicker::make('from')
                            ->label('З дати')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->required()
                            ->live(),

                        DatePicker::make('to')
                            ->label('По дату')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->required()
                            ->afterOrEqual('from')
                            ->live(),

                        DatePicker::make('missing_date')
                            ->label('Хто не замовляв — на дату')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->required()
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    public function getDays(): Collection
    {
        return app(SchoolSummaryService::class)->byDay(
            $this->data['from'] ?? today(),
            $this->data['to'] ?? today(),
        );
    }

    public function getMissingStudents(): Collection
    {
        return app(SchoolSummaryService::class)
            ->studentsWithoutOrders($this->data['missing_date'] ?? today());
    }

    public function getMissingDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->data['missing_date'] ?? today());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('Друк / PDF')
                ->icon('heroicon-o-printer')
                ->action(fn (): Response => $this->downloadPdf()),
        ];
    }

    private function downloadPdf(): Response
    {
        $days = $this->getDays();

        $pdf = Pdf::loadView('reports.school-summary-pdf', [
            'schoolName' => Setting::get('school_name', ''),
            'from' => CarbonImmutable::parse($this->data['from']),
            'to' => CarbonImmutable::parse($this->data['to']),
            'days' => $days,
            'supplierNames' => $days
                ->flatMap(fn (array $day): array => $day['suppliers']->keys()->all())
                ->unique()
                ->values(),
            'missing' => $this->getMissingStudents(),
            'missingDate' => $this->getMissingDate(),
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            sprintf('zvedennia-%s-%s.pdf', $this->data['from'], $this->data['to']),
        );
    }
}
