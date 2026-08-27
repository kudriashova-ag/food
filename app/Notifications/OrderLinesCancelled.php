<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Notifications\Concerns\DeliversToStudent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Один лист на всю операцію скасування — інакше при скасуванні дня
 * учень отримав би окремий лист на кожну страву.
 */
class OrderLinesCancelled extends Notification implements ShouldQueue
{
    use DeliversToStudent, Queueable;

    /**
     * @param  Collection<int, \App\Models\OrderLine>  $lines
     * @param  bool  $byAdministrator  Скасування школою — тоді причина обов'язкова.
     */
    public function __construct(
        public readonly Collection $lines,
        public readonly bool $byAdministrator = false,
        public readonly ?string $reason = null,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage())
            ->subject($this->byAdministrator ? 'Замовлення скасовано школою' : 'Замовлення скасовано')
            ->greeting($this->byAdministrator ? 'Школа скасувала замовлення' : 'Замовлення скасовано');

        foreach ($this->lines->groupBy(fn ($line) => $line->service_date->toDateString()) as $lines) {
            $message->line('**'.$lines->first()->service_date->translatedFormat('l, d.m.Y').'**');

            foreach ($lines as $line) {
                $message->line(sprintf(
                    '%s%s (%s)',
                    $line->dish_name,
                    $line->quantity > 1 ? " ×{$line->quantity}" : '',
                    $line->supplier->name,
                ));
            }

            $message->line('');
        }

        if ($this->byAdministrator && filled($this->reason)) {
            $message->line("Причина: {$this->reason}");
        }

        return $message->salutation(Setting::get('notification_signature', 'Шкільна їдальня'));
    }

    public function toTelegram(object $notifiable): string
    {
        $text = $this->byAdministrator
            ? "<b>Школа скасувала замовлення</b>\n"
            : "<b>Замовлення скасовано</b>\n";

        foreach ($this->lines->groupBy(fn ($line) => $line->service_date->toDateString()) as $lines) {
            $text .= "\n<b>".$lines->first()->service_date->translatedFormat('d.m, l')."</b>\n";

            foreach ($lines as $line) {
                $text .= '• '.$line->dish_name.($line->quantity > 1 ? " ×{$line->quantity}" : '')."\n";
            }
        }

        if ($this->byAdministrator && filled($this->reason)) {
            $text .= "\nПричина: {$this->reason}";
        }

        return $text;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->byAdministrator ? 'cancelled_by_admin' : 'cancelled_by_student',
            'lines' => $this->lines->pluck('id')->all(),
        ];
    }
}
