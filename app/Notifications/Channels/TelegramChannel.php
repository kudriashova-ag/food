<?php

namespace App\Notifications\Channels;

use App\Models\NotificationLog;
use App\Models\TelegramLink;
use App\Models\User;
use App\Services\Telegram\TelegramClient;
use Illuminate\Notifications\Notification;

/**
 * Розсилка в усі активні чати учня. При 403 прив'язка гаситься,
 * щоб не довбати заблокованого бота на кожному сповіщенні (ТЗ, п. 12.2).
 */
class TelegramChannel
{
    public function __construct(private readonly TelegramClient $client) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User || ! method_exists($notification, 'toTelegram')) {
            return;
        }

        $student = $notifiable->student;

        if ($student === null) {
            return;
        }

        $text = $notification->toTelegram($notifiable);

        $links = TelegramLink::query()
            ->where('student_id', $student->id)
            ->where('is_active', true)
            ->get();

        foreach ($links as $link) {
            $result = $this->client->sendMessage($link->chat_id, $text);

            if ($result['blocked']) {
                $link->deactivate();
            }

            $this->log($student->id, $link, $notification, $notifiable, $result['ok']);
        }
    }

    private function log(int $studentId, TelegramLink $link, Notification $notification, User $notifiable, bool $ok): void
    {
        $payload = method_exists($notification, 'toArray')
            ? $notification->toArray($notifiable)
            : [];

        NotificationLog::create([
            'student_id' => $studentId,
            'channel' => 'telegram',
            'event' => $payload['event'] ?? class_basename($notification),
            'recipient' => $link->chat_id,
            'payload' => $payload,
            'status' => $ok ? 'sent' : 'failed',
            'sent_at' => $ok ? now() : null,
        ]);
    }
}
