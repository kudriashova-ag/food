<?php

namespace App\Notifications\Concerns;

use App\Notifications\Channels\TelegramChannel;

/**
 * Канали доставки для учня: e-mail базовий, Telegram — якщо підключений (ТЗ, п. 12.1).
 * Якщо не налаштовано жодного, сповіщення просто не йде.
 */
trait DeliversToStudent
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (filled($notifiable->email)) {
            $channels[] = 'mail';
        }

        if ($notifiable->student?->telegramLinks()->where('is_active', true)->exists()) {
            $channels[] = TelegramChannel::class;
        }

        return $channels;
    }
}
