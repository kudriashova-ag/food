<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Exports\OrdersByPeriodExport;
use App\Filament\Resources\Orders\OrderResource;
use App\Services\Reports\OrderExportService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            static::exportAction(
                name: 'exportPupils',
                label: 'Експорт: учні',
                report: fn (CarbonImmutable $from, CarbonImmutable $to) => app(OrderExportService::class)->pupilReport($from, $to),
                filenamePrefix: 'zamovlennia-uchni',
            ),
            static::exportAction(
                name: 'exportTeachers',
                label: 'Експорт: вчителі',
                report: fn (CarbonImmutable $from, CarbonImmutable $to) => app(OrderExportService::class)->teacherReport($from, $to),
                filenamePrefix: 'zamovlennia-vchyteli',
            ),
        ];
    }

    /** @param callable(CarbonImmutable, CarbonImmutable): array{rows: \Illuminate\Support\Collection, suppliers: \Illuminate\Support\Collection} $report */
    private static function exportAction(string $name, string $label, callable $report, string $filenamePrefix): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->modalHeading($label)
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
            ->action(function (array $data) use ($report, $filenamePrefix): BinaryFileResponse {
                $from = CarbonImmutable::parse($data['from'])->startOfDay();
                $to = CarbonImmutable::parse($data['to'])->startOfDay();

                $result = $report($from, $to);

                $export = new OrdersByPeriodExport($result['rows'], $result['suppliers'], $from, $to);

                $path = sprintf('exports/%s.xlsx', Str::uuid());
                $export->store($path, 'local');

                return response()->download(
                    Storage::disk('local')->path($path),
                    sprintf('%s-%s-%s.xlsx', $filenamePrefix, $from->toDateString(), $to->toDateString()),
                )->deleteFileAfterSend();
            });
    }

    /** Понеділок наступного тижня — явно, без покладання на дефолтну локаль (як MenuTemplateService). */
    private static function nextWeekStart(): CarbonInterface
    {
        return today()->addWeek()->startOfWeek(CarbonInterface::MONDAY);
    }

    private static function nextWeekEnd(): CarbonInterface
    {
        return static::nextWeekStart()->addDays(4);
    }
}
