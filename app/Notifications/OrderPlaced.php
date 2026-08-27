<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\Setting;
use App\Notifications\Concerns\DeliversToStudent;
use App\Services\Deadlines\DeadlineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPlaced extends Notification implements ShouldQueue
{
    use DeliversToStudent, Queueable;

    public function __construct(public readonly Order $order) {}

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['lines.supplier', 'student']);
        $deadlines = app(DeadlineService::class);

        $message = (new MailMessage())
            ->subject("Замовлення {$order->number} прийнято")
            ->greeting('Замовлення прийнято')
            ->line("Учень: {$order->student->full_name}")
            ->line("Номер замовлення: {$order->number}");

        foreach ($order->lines()->active()->get()->groupBy(fn ($line) => $line->service_date->toDateString()) as $date => $lines) {
            $first = $lines->first();
            $cancelDeadline = $deadlines->for($first->supplier_id, $first->service_date);

            $message->line('');
            $message->line('**'.$first->service_date->translatedFormat('l, d.m.Y').'**');

            foreach ($lines as $line) {
                $message->line(sprintf(
                    '%s%s — %s грн',
                    $line->dish_name,
                    $line->quantity > 1 ? " ×{$line->quantity}" : '',
                    number_format($line->subtotal(), 2, ',', ' '),
                ));
            }

            if ($cancelDeadline->cancelAt !== null) {
                $message->line('Скасувати можна до '.$cancelDeadline->cancelAt->translatedFormat('D, d.m, H:i'));
            }
        }

        return $message
            ->line('')
            ->line('**Сума: '.number_format((float) $order->total_amount, 2, ',', ' ').' грн**')
            ->line('Оплата відбувається поза сайтом.')
            ->salutation(Setting::get('notification_signature', 'Шкільна їдальня'));
    }

    /** У Telegram — мінімум персональних даних: ПІБ, дата, страви (ТЗ, п. 12.2). */
    public function toTelegram(object $notifiable): string
    {
        $order = $this->order->loadMissing(['lines', 'student']);

        $text = "<b>Замовлення прийнято</b>\n{$order->student->full_name}\nНомер: {$order->number}\n";

        foreach ($order->lines()->active()->get()->groupBy(fn ($line) => $line->service_date->toDateString()) as $lines) {
            $text .= "\n<b>".$lines->first()->service_date->translatedFormat('d.m, l')."</b>\n";

            foreach ($lines as $line) {
                $text .= '• '.$line->dish_name.($line->quantity > 1 ? " ×{$line->quantity}" : '')."\n";
            }
        }

        return $text."\nСума: ".number_format((float) $order->total_amount, 2, ',', ' ').' грн';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'order_placed',
            'order_number' => $this->order->number,
        ];
    }
}
