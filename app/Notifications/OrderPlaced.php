<?php

namespace App\Notifications;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Setting;
use App\Models\Supplier;
use App\Notifications\Concerns\DeliversToStudent;
use App\Services\Deadlines\DeadlineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class OrderPlaced extends Notification implements ShouldQueue
{
    use DeliversToStudent, Queueable;

    public function __construct(public readonly Order $order) {}

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['lines.supplier', 'student.schoolClass']);
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

        $message
            ->line('')
            ->line('**Сума: '.number_format((float) $order->total_amount, 2, ',', ' ').' грн**');

        foreach ($this->paymentGroups($order) as $group) {
            $message->line('');
            $message->line(sprintf(
                '**Сума для оплати %s: %s грн**',
                $group['supplier']->name,
                number_format($group['total'], 2, ',', ' '),
            ));

            if (filled($group['supplier']->payment_details)) {
                $message->line($group['supplier']->payment_details);
            }

            $message->line("Призначення платежу: {$group['purpose']}");
        }

        return $message
            ->line('')
            ->line('Оплата відбувається поза сайтом.')
            ->salutation(Setting::get('notification_signature', 'Шкільна їдальня'));
    }

    /** У Telegram — мінімум персональних даних: ПІБ, дата, страви (ТЗ, п. 12.2). */
    public function toTelegram(object $notifiable): string
    {
        $order = $this->order->loadMissing(['lines.supplier', 'student.schoolClass']);

        $text = "<b>Замовлення прийнято</b>\n{$order->student->full_name}\nНомер: {$order->number}\n";

        foreach ($order->lines()->active()->get()->groupBy(fn ($line) => $line->service_date->toDateString()) as $lines) {
            $text .= "\n<b>".$lines->first()->service_date->translatedFormat('d.m, l')."</b>\n";

            foreach ($lines as $line) {
                $text .= '• '.$line->dish_name.($line->quantity > 1 ? " ×{$line->quantity}" : '')."\n";
            }
        }

        $text .= "\nСума: ".number_format((float) $order->total_amount, 2, ',', ' ').' грн'."\n";

        foreach ($this->paymentGroups($order) as $group) {
            $text .= "\n<b>Сума для оплати {$group['supplier']->name}: ".number_format($group['total'], 2, ',', ' ')." грн</b>\n";

            if (filled($group['supplier']->payment_details)) {
                $text .= $group['supplier']->payment_details."\n";
            }

            $text .= "Призначення платежу: {$group['purpose']}\n";
        }

        return $text;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'order_placed',
            'order_number' => $this->order->number,
        ];
    }

    /**
     * Розбивка суми до сплати по кожному постачальнику, що є в цьому
     * замовленні: сума лише його активних позицій, реквізити з профілю
     * постачальника, і готовий текст призначення платежу з періодом
     * саме цього постачальника в цьому замовленні (не всього замовлення).
     *
     * @return Collection<int, array{supplier: Supplier, total: float, purpose: string}>
     */
    private function paymentGroups(Order $order): Collection
    {
        $lines = $order->lines()->active()->with('supplier')->get();

        if ($lines->isEmpty()) {
            return collect();
        }

        $student = $order->student;

        $who = $student->isTeacher()
            ? "{$student->full_name}, вчитель"
            : "{$student->full_name}, {$student->schoolClass?->title}";

        return $lines
            ->groupBy('supplier_id')
            ->map(function (Collection $supplierLines) use ($who): array {
                $dates = $supplierLines->pluck('service_date')->sort();

                $period = $dates->first()->isSameDay($dates->last())
                    ? $dates->first()->translatedFormat('d.m')
                    : $dates->first()->translatedFormat('d.m').'-'.$dates->last()->translatedFormat('d.m');

                return [
                    'supplier' => $supplierLines->first()->supplier,
                    'total' => $supplierLines->sum(fn (OrderLine $line): float => $line->subtotal()),
                    'purpose' => "{$who}, оплата за {$period}",
                ];
            })
            ->sortBy(fn (array $group): string => $group['supplier']->name)
            ->values();
    }
}
