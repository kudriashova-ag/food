<?php

namespace App\Services\Reports;

use App\Mail\SupplierDigestMail;
use App\Models\NotificationLog;
use App\Models\Supplier;
use App\Models\SupplierDigest;
use App\Services\Telegram\TelegramClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * Вечірнє зведення для кухні.
 *
 * Логіка повторів: зведення на дату надсилається один раз до закриття приймання
 * (попереднє) і один раз після (уточнене). Далі про зміни повідомляє
 * SupplierCancellationAlerts.
 */
class SupplierDigestService
{
    public function __construct(
        private readonly DigestPresenter $presenter,
        private readonly TelegramClient $telegram,
    ) {}

    /**
     * @return bool  чи було надіслано зведення
     */
    public function send(Supplier $supplier, CarbonImmutable $date): bool
    {
        $data = $this->presenter->data($supplier, $date);

        if ($data['positions'] === 0) {
            return false;
        }

        if ($this->alreadySent($supplier, $date, $data['is_final'])) {
            return false;
        }

        $this->sendMail($supplier, $date, $data);
        $this->sendTelegram($supplier, $date, $data);

        SupplierDigest::create([
            'supplier_id' => $supplier->id,
            'service_date' => $date->toDateString(),
            'is_final' => $data['is_final'],
            'positions' => $data['positions'],
            'sent_at' => now(),
        ]);

        return true;
    }

    /** Зведення на вимогу — кнопкою в боті. Не впливає на журнал розсилки. */
    public function textFor(Supplier $supplier, CarbonImmutable $date): string
    {
        return $this->presenter->telegramText($supplier, $date, $this->presenter->data($supplier, $date));
    }

    private function alreadySent(Supplier $supplier, CarbonImmutable $date, bool $isFinal): bool
    {
        $query = SupplierDigest::query()
            ->where('supplier_id', $supplier->id)
            ->whereDate('service_date', $date->toDateString());

        // Попереднє зведення не блокує уточнене, а уточнене — блокує все.
        return $isFinal
            ? $query->where('is_final', true)->exists()
            : $query->exists();
    }

    private function sendMail(Supplier $supplier, CarbonImmutable $date, array $data): void
    {
        $recipients = $supplier->reportRecipients();

        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->queue(new SupplierDigestMail($supplier, $date, $data));

        NotificationLog::create([
            'channel' => 'mail',
            'event' => 'supplier_digest',
            'recipient' => implode(', ', $recipients),
            'payload' => [
                'event' => 'supplier_digest',
                'supplier_id' => $supplier->id,
                'service_date' => $date->toDateString(),
                'is_final' => $data['is_final'],
            ],
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    private function sendTelegram(Supplier $supplier, CarbonImmutable $date, array $data): void
    {
        $text = $this->presenter->telegramText($supplier, $date, $data);

        foreach ($supplier->telegramLinks()->active()->get() as $link) {
            $result = $this->telegram->sendMessage($link->chat_id, $text);

            if ($result['blocked']) {
                $link->deactivate();
            }

            NotificationLog::create([
                'channel' => 'telegram',
                'event' => 'supplier_digest',
                'recipient' => $link->chat_id,
                'payload' => [
                    'event' => 'supplier_digest',
                    'supplier_id' => $supplier->id,
                    'service_date' => $date->toDateString(),
                ],
                'status' => $result['ok'] ? 'sent' : 'failed',
                'sent_at' => $result['ok'] ? now() : null,
            ]);
        }
    }
}
