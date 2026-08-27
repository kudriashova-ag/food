<?php

namespace App\Notifications;

use App\Models\Setting;
use App\Notifications\Concerns\DeliversToStudent;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Нагадування за N годин до закриття приймання замовлень (ТЗ, п. 12.3). */
class DeadlineReminder extends Notification implements ShouldQueue
{
    use DeliversToStudent, Queueable;

    public function __construct(
        public readonly CarbonInterface $serviceDate,
        public readonly CarbonInterface $deadlineAt,
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Нагадування: харчування на '.$this->serviceDate->translatedFormat('d.m.Y').' ще не замовлено')
            ->greeting('Харчування ще не замовлено')
            ->line('На '.$this->serviceDate->translatedFormat('l, d.m.Y').' замовлення не оформлене.')
            ->line('Приймання закривається '.$this->deadlineAt->translatedFormat('D, d.m, H:i').'.')
            ->action('Замовити', url('/'))
            ->salutation(Setting::get('notification_signature', 'Шкільна їдальня'));
    }

    public function toTelegram(object $notifiable): string
    {
        return "<b>Харчування ще не замовлено</b>\n"
            .'На '.$this->serviceDate->translatedFormat('d.m, l')." замовлення немає.\n"
            .'Приймання закривається '.$this->deadlineAt->translatedFormat('d.m о H:i').'.';
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => 'deadline_reminder',
            'service_date' => $this->serviceDate->toDateString(),
        ];
    }
}
