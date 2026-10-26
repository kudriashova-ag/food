<?php

namespace App\Filament\Supplier\Resources\Orders\Pages;

use App\Filament\Supplier\Concerns\ExportsOrdersReport;
use App\Filament\Supplier\Resources\Orders\OrderResource;
use App\Services\Reports\SupplierOrdersWeekService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Таблиця «хто що замовив» за вибраний період — та сама, що й у Excel-звіті
 * «Замовлення за тиждень». За замовчуванням — поточний робочий тиждень.
 */
class ListOrders extends Page implements HasForms
{
    use ExportsOrdersReport, InteractsWithForms;

    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.supplier.pages.orders-week';

    protected static ?string $title = 'Замовлення';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $monday = today()->startOfWeek(CarbonInterface::MONDAY);

        $this->form->fill([
            'from' => $monday->toDateString(),
            'to' => $monday->addDays(4)->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
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
                            ->live(),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Дати періоду; переплутані місцями «з» і «по» просто міняємо,
     * щоб таблиця не ставала порожньою через описку.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function getPeriod(): array
    {
        $from = CarbonImmutable::parse($this->data['from'] ?? today())->startOfDay();
        $to = CarbonImmutable::parse($this->data['to'] ?? $from)->startOfDay();

        return $from->lessThanOrEqualTo($to) ? [$from, $to] : [$to, $from];
    }

    /** @return array{columns: \Illuminate\Support\Collection, rows: \Illuminate\Support\Collection} */
    public function getReport(): array
    {
        [$from, $to] = $this->getPeriod();

        return app(SupplierOrdersWeekService::class)->build($this->supplier(), $from, $to);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->weekOrdersExportAction(),
            $this->dayOrdersExportAction(),
        ];
    }
}
