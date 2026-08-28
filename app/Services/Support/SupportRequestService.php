<?php

namespace App\Services\Support;

use App\Mail\SupportRequestMail;
use App\Models\Setting;
use App\Models\SupportRequest;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Звернення з форми «Допомога»: зберігаємо в базі, далі повідомляємо адміністратора
 * поштою й у Telegram.
 *
 * Відмова каналу не втрачає питання — запис лишається, і його видно в панелі.
 */
class SupportRequestService
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function submit(SupportRequest $request): void
    {
        $delivered = false;

        $delivered = $this->sendEmail($request) || $delivered;
        $delivered = $this->sendTelegram($request) || $delivered;

        if ($delivered) {
            $request->forceFill(['notified_at' => now()])->save();
        }
    }

    private function sendEmail(SupportRequest $request): bool
    {
        $to = Setting::get('support_email');

        if (blank($to)) {
            return false;
        }

        // Черга віддає лист cron'ом; помилка конфігурації не має ронити форму.
        try {
            Mail::to($to)->send(new SupportRequestMail($request));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Не вдалося поставити звернення в чергу', ['error' => $e->getMessage()]);

            return false;
        }
    }

    private function sendTelegram(SupportRequest $request): bool
    {
        $chatId = Setting::get('support_telegram_chat_id');

        if (blank($chatId) || ! $this->telegram->isConfigured()) {
            return false;
        }

        $text = implode("\n", [
            '❓ <b>Питання з сайту</b>',
            '',
            'Від: '.e($request->name),
            'Email: '.e($request->email),
            '',
            e($request->message),
        ]);

        return $this->telegram->sendMessage((string) $chatId, $text)['ok'];
    }
}
