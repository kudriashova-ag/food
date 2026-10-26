<?php

namespace App\Filament\Supplier\Concerns;

use App\Exports\SupplierOrdersWeekExport;
use App\Models\Supplier;
use App\Services\Reports\SupplierOrdersWeekService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Вивантаження звіту «хто що замовив» у Excel: за тиждень (діапазон дат)
 * і за один день — той самий звіт, лише на одну дату.
 */
trait ExportsOrdersReport
{
    /** Тижневий звіт замовлень: хто що замовив, по днях і секціях меню. */
    protected function weekOrdersExportAction(): Action
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
                    ->default(static::nextWeekStart()->addDays(4)->toDateString()),
            ])
            ->action(function (array $data): BinaryFileResponse {
                $from = CarbonImmutable::parse($data['from'])->startOfDay();
                $to = CarbonImmutable::parse($data['to'])->startOfDay();

                return $this->downloadOrders(
                    $from,
                    $to,
                    sprintf('zamovlennia-%s-%s-%s.xlsx', $this->supplier()->slug, $from->toDateString(), $to->toDateString()),
                );
            });
    }

    /** Той самий звіт, що й «Замовлення за тиждень», але на одну дату харчування. */
    protected function dayOrdersExportAction(): Action
    {
        return Action::make('excel')
            ->label('Експорт у Excel')
            ->icon('heroicon-o-table-cells')
            ->modalHeading('Експорт замовлень за день')
            ->modalSubmitActionLabel('Завантажити')
            ->schema([
                DatePicker::make('date')
                    ->label('Дата харчування')
                    ->native(false)
                    ->displayFormat('d.m.Y')
                    ->required()
                    ->default(today()->toDateString()),
            ])
            ->action(function (array $data): BinaryFileResponse {
                $date = CarbonImmutable::parse($data['date'])->startOfDay();

                return $this->downloadOrders(
                    $date,
                    $date,
                    sprintf('zamovlennia-%s-%s.xlsx', $this->supplier()->slug, $date->toDateString()),
                );
            });
    }

    private function downloadOrders(CarbonImmutable $from, CarbonImmutable $to, string $fileName): BinaryFileResponse
    {
        $report = app(SupplierOrdersWeekService::class)->build($this->supplier(), $from, $to);

        $export = new SupplierOrdersWeekExport($report['columns'], $report['rows']);

        $path = sprintf('exports/%s.xlsx', Str::uuid());
        $export->store($path, 'local');

        return response()->download(Storage::disk('local')->path($path), $fileName)->deleteFileAfterSend();
    }

    /** Понеділок наступного тижня — явно, без покладання на дефолтну локаль. */
    private static function nextWeekStart(): CarbonInterface
    {
        return today()->addWeek()->startOfWeek(CarbonInterface::MONDAY);
    }

    private function supplier(): Supplier
    {
        return auth()->user()->supplier;
    }
}
