<?php

namespace App\Console\Commands;

use App\Services\Telegram\TelegramClient;
use Illuminate\Console\Command;

/** Викликається один раз після розгортання: php artisan telegram:webhook */
class SetTelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook {--delete : Прибрати вебхук}';

    protected $description = 'Зареєструвати адресу вебхука Telegram-бота';

    public function handle(TelegramClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('Не заданий TELEGRAM_BOT_TOKEN.');

            return self::FAILURE;
        }

        if ($this->option('delete')) {
            $this->info($client->deleteWebhook() ? 'Вебхук прибрано.' : 'Не вдалося прибрати вебхук.');

            return self::SUCCESS;
        }

        $secret = config('services.telegram.webhook_secret');

        if (blank($secret)) {
            $this->error('Не заданий TELEGRAM_WEBHOOK_SECRET.');

            return self::FAILURE;
        }

        // Спецсимволи в адресі кодуються (! → %21), і тоді шлях перестає
        // збігатися з винятком CSRF — Telegram отримує 403 і бот мовчить.
        if (! preg_match('/^[A-Za-z0-9_-]{16,}$/', $secret)) {
            $this->error('TELEGRAM_WEBHOOK_SECRET має бути щонайменше 16 символів і містити лише латиницю, цифри, дефіс або підкреслення.');
            $this->line('Згенерувати придатний: php -r "echo bin2hex(random_bytes(24));"');

            return self::FAILURE;
        }

        $url = route('telegram.webhook', ['secret' => $secret]);

        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram приймає лише HTTPS. Поточна адреса: {$url}");

            return self::FAILURE;
        }

        if (! $client->setWebhook($url)) {
            $this->error('Telegram відхилив адресу вебхука.');

            return self::FAILURE;
        }

        $this->info("Вебхук зареєстровано: {$url}");

        return self::SUCCESS;
    }
}
