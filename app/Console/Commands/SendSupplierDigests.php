<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Reports\SupplierDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Вечірнє зведення на завтра. Запускається щохвилини планувальником:
 * кожен постачальник має власний час, тож команда сама вирішує, кому вже пора.
 *
 * Друга роль команди — дослати уточнене зведення після закриття приймання,
 * якщо на момент вечірньої розсилки дедлайн ще не минув.
 */
class SendSupplierDigests extends Command
{
    protected $signature = 'school:send-supplier-digests {--date= : Надіслати за конкретну дату}';

    protected $description = 'Надіслати постачальникам зведення замовлень на завтра';

    public function handle(SupplierDigestService $digests): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;

        foreach (Supplier::query()->where('digest_enabled', true)->get() as $supplier) {
            foreach ($this->datesFor($supplier, $now) as $date) {
                if ($digests->send($supplier, $date)) {
                    $sent++;
                    $this->line("{$supplier->name}: {$date->toDateString()}");
                }
            }
        }

        $this->info("Надіслано зведень: {$sent}.");

        return self::SUCCESS;
    }

    /** @return array<int, CarbonImmutable> */
    private function datesFor(Supplier $supplier, CarbonImmutable $now): array
    {
        if ($this->option('date')) {
            return [CarbonImmutable::parse($this->option('date'))->startOfDay()];
        }

        $dates = [];

        // Настав час вечірньої розсилки — зведення на завтра.
        if ($this->isDigestTime($supplier, $now)) {
            $dates[] = $now->addDay()->startOfDay();
        }

        // Найближчі дні перевіряємо на випадок, коли попереднє зведення вже пішло,
        // а приймання закрилося щойно — тоді треба дослати уточнене.
        foreach ([0, 1, 2] as $offset) {
            $dates[] = $now->addDays($offset)->startOfDay();
        }

        return collect($dates)
            ->unique(fn (CarbonImmutable $date): string => $date->toDateString())
            ->values()
            ->all();
    }

    private function isDigestTime(Supplier $supplier, CarbonImmutable $now): bool
    {
        $time = substr((string) $supplier->digest_time, 0, 5);

        return $now->format('H:i') === $time;
    }
}
