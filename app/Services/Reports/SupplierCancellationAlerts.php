<?php

namespace App\Services\Reports;

use App\Models\NotificationLog;
use App\Models\OrderLine;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDigest;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

/**
 * Повідомлення кухні про скасування, які сталися вже після надісланого зведення.
 *
 * До зведення скасування просто змінюють його цифри — окремий сигнал там зайвий.
 * Після — кухня вже рахує продукти, тож про кожну зміну треба сказати.
 */
class SupplierCancellationAlerts
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /** @return int кількість надісланих повідомлень */
    public function dispatchPending(): int
    {
        $sent = 0;

        $suppliers = Supplier::query()
            ->where('cancellation_alerts_enabled', true)
            ->get();

        foreach ($suppliers as $supplier) {
            foreach ($this->pendingByDate($supplier) as $lines) {
                if ($this->notify($supplier, $lines)) {
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /**
     * Скасовані позиції, про які кухню ще не повідомляли.
     *
     * @return Collection<string, Collection<int, OrderLine>>
     */
    private function pendingByDate(Supplier $supplier): Collection
    {
        $digests = SupplierDigest::query()
            ->where('supplier_id', $supplier->id)
            ->whereDate('service_date', '>=', today()->toDateString())
            ->get()
            ->groupBy(fn (SupplierDigest $digest): string => $digest->service_date->toDateString());

        if ($digests->isEmpty()) {
            return collect();
        }

        $alreadyReported = $this->reportedLineIds($supplier);

        return $digests
            ->map(function (Collection $dateDigests, string $date) use ($supplier, $alreadyReported): Collection {
                $sentAt = $dateDigests->min('sent_at');

                return OrderLine::query()
                    ->where('supplier_id', $supplier->id)
                    ->whereDate('service_date', $date)
                    ->where('status', \App\Enums\OrderLineStatus::Cancelled)
                    ->where('cancelled_at', '>', $sentAt)
                    ->whereNotIn('id', $alreadyReported)
                    ->with(['student.schoolClass', 'canceller'])
                    ->get();
            })
            ->filter(fn (Collection $lines): bool => $lines->isNotEmpty());
    }

    /** @return array<int, int> */
    private function reportedLineIds(Supplier $supplier): array
    {
        return NotificationLog::query()
            ->where('event', 'supplier_cancellations')
            ->whereJsonContains('payload->supplier_id', $supplier->id)
            ->get()
            ->flatMap(fn (NotificationLog $log): array => $log->payload['line_ids'] ?? [])
            ->unique()
            ->values()
            ->all();
    }

    /** @param Collection<int, OrderLine> $lines */
    private function notify(Supplier $supplier, Collection $lines): bool
    {
        $date = $lines->first()->service_date;
        $recipients = $supplier->reportRecipients();
        $chats = $supplier->telegramLinks()->active()->get();

        if ($recipients === [] && $chats->isEmpty()) {
            return false;
        }

        $text = $this->text($supplier, $lines);

        if ($recipients !== []) {
            Mail::raw($text, function ($message) use ($recipients, $supplier, $date): void {
                $message->to($recipients)
                    ->subject(sprintf(
                        '%s: скасування на %s',
                        $supplier->name,
                        $date->translatedFormat('d.m.Y'),
                    ));
            });
        }

        foreach ($chats as $link) {
            $result = $this->telegram->sendMessage($link->chat_id, $this->telegramText($lines));

            if ($result['blocked']) {
                $link->deactivate();
            }
        }

        NotificationLog::create([
            'channel' => $recipients !== [] ? 'mail' : 'telegram',
            'event' => 'supplier_cancellations',
            'recipient' => implode(', ', $recipients) ?: $chats->pluck('chat_id')->implode(', '),
            'payload' => [
                'event' => 'supplier_cancellations',
                'supplier_id' => $supplier->id,
                'service_date' => $date->toDateString(),
                'line_ids' => $lines->pluck('id')->all(),
            ],
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        return true;
    }

    /** @param Collection<int, OrderLine> $lines */
    private function text(Supplier $supplier, Collection $lines): string
    {
        $date = $lines->first()->service_date;

        $text = sprintf(
            "Скасування після надісланого зведення на %s.\n\n",
            $date->translatedFormat('l, d.m.Y'),
        );

        foreach ($this->groupDishes($lines) as $name => $quantity) {
            $text .= sprintf("− %s: %d\n", $name, $quantity);
        }

        $text .= "\n".$this->reasonsBlock($lines);

        return $text."\n".Setting::get('notification_signature', 'Шкільна їдальня');
    }

    /** @param Collection<int, OrderLine> $lines */
    private function telegramText(Collection $lines): string
    {
        $date = $lines->first()->service_date;

        $text = sprintf("<b>Скасування на %s</b>\n", $date->translatedFormat('d.m, l'));

        foreach ($this->groupDishes($lines) as $name => $quantity) {
            $text .= sprintf("\n− %s: <b>%d</b>", $name, $quantity);
        }

        return $text."\n\n".$this->reasonsBlock($lines);
    }

    /** @param Collection<int, OrderLine> $lines */
    private function groupDishes(Collection $lines): array
    {
        return $lines
            ->groupBy('dish_name')
            ->map(fn (Collection $group): int => (int) $group->sum('quantity'))
            ->sortDesc()
            ->all();
    }

    /** Причини вказує адміністратор — кухні корисно бачити, чому зняли порції. */
    private function groupReasons(Collection $lines): array
    {
        return $lines
            ->filter(fn (OrderLine $line): bool => filled($line->cancel_reason))
            ->pluck('cancel_reason')
            ->unique()
            ->values()
            ->all();
    }

    private function reasonsBlock(Collection $lines): string
    {
        $reasons = $this->groupReasons($lines);

        return $reasons === [] ? '' : 'Причина: '.implode('; ', $reasons);
    }
}
