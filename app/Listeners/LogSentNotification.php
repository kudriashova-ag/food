<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * Журнал фактичних відправок. Потрібен і для звітності, і для того,
 * щоб нагадування не надсилалося двічі на ту саму дату.
 */
class LogSentNotification
{
    public function handle(NotificationSent $event): void
    {
        $notifiable = $event->notifiable;

        // Telegram веде власний запис — окремо на кожен чат, з його статусом.
        if (! $notifiable instanceof User || $event->channel !== 'mail') {
            return;
        }

        $payload = method_exists($event->notification, 'toArray')
            ? $event->notification->toArray($notifiable)
            : [];

        NotificationLog::create([
            'student_id' => $notifiable->student?->id,
            'channel' => 'mail',
            'event' => $payload['event'] ?? class_basename($event->notification),
            'recipient' => $notifiable->email,
            'payload' => $payload,
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }
}
