<?php

namespace App\Console\Commands;

use App\Models\MenuDay;
use App\Models\OrderLine;
use App\Models\Supplier;
use App\Services\GoogleSheets\GoogleSheetsClient;
use App\Services\GoogleSheets\OrdersWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Оновлює в Google Таблиці вкладки поточного тижня (і, з --next, наступного)
 * для постачальників, у яких на тиждень є меню чи замовлення, і ставить
 * вкладки поточного тижня першими. Щопонеділка — за розкладом.
 */
class SyncGoogleSheet extends Command
{
    protected $signature = 'school:sync-google-sheet {--next : Також наступний тиждень}';

    protected $description = 'Оновити в Google Таблиці вкладки замовлень поточного тижня';

    public function handle(OrdersWorkbook $workbook): int
    {
        if (! GoogleSheetsClient::isConfigured()) {
            $this->warn('Google Таблиця не налаштована — пропускаю.');

            return self::SUCCESS;
        }

        $mondays = [OrdersWorkbook::weekStart(today())];

        if ($this->option('next')) {
            $mondays[] = $mondays[0]->addWeek();
        }

        $written = 0;

        foreach ($mondays as $monday) {
            foreach ($this->suppliersFor($monday) as $supplier) {
                $workbook->writeWeek($supplier, $monday);
                $written++;
            }
        }

        // Навіть коли нового тижня ще немає, старі вкладки мають з'їхати вправо.
        $workbook->reorderTabs();

        $this->info("Оновлено вкладок: {$written}.");

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Supplier> */
    private function suppliersFor(CarbonImmutable $monday): \Illuminate\Support\Collection
    {
        $from = $monday->toDateString();
        $to = $monday->addDays(6)->toDateString();

        $ids = MenuDay::query()->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->pluck('supplier_id')
            ->merge(OrderLine::query()->whereDate('service_date', '>=', $from)->whereDate('service_date', '<=', $to)->pluck('supplier_id'))
            ->unique();

        return Supplier::query()->whereIn('id', $ids)->orderBy('name')->get();
    }
}
