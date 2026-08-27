<?php

namespace App\Console\Commands;

use App\Services\Reports\SupplierCancellationAlerts;
use Illuminate\Console\Command;

class SendCancellationAlerts extends Command
{
    protected $signature = 'school:send-cancellation-alerts';

    protected $description = 'Повідомити постачальників про скасування, що сталися після надісланого зведення';

    public function handle(SupplierCancellationAlerts $alerts): int
    {
        $sent = $alerts->dispatchPending();

        $this->info("Надіслано повідомлень про скасування: {$sent}.");

        return self::SUCCESS;
    }
}
