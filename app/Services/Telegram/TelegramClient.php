<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Тонка обгортка над Bot API: нам потрібні лише надсилання повідомлень і вебхук. */
class TelegramClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    /**
     * @param  array<int, array<int, array<string, string>>>|null  $keyboard  кнопки під повідомленням
     * @return array{ok: bool, blocked: bool}  blocked = користувач заблокував бота (403)
     */
    public function sendMessage(string $chatId, string $text, ?array $keyboard = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'blocked' => false];
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ];

        if ($keyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $keyboard];
        }

        $response = Http::asJson()
            ->timeout(15)
            ->post($this->endpoint('sendMessage'), $payload);

        if ($response->successful()) {
            return ['ok' => true, 'blocked' => false];
        }

        // 403 — бота заблоковано або чат видалено: прив'язку треба погасити.
        $blocked = $response->status() === 403;

        if (! $blocked) {
            Log::warning('Telegram sendMessage failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        return ['ok' => false, 'blocked' => $blocked];
    }

    /** Прибирає «годинник» на кнопці після натискання. */
    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        if (! $this->isConfigured()) {
            return;
        }

        Http::asJson()
            ->timeout(10)
            ->post($this->endpoint('answerCallbackQuery'), array_filter([
                'callback_query_id' => $callbackQueryId,
                'text' => $text,
            ]));
    }

    public function setWebhook(string $url): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        return Http::asJson()
            ->post($this->endpoint('setWebhook'), [
                'url' => $url,
                'allowed_updates' => ['message', 'callback_query'],
            ])
            ->successful();
    }

    public function deleteWebhook(): bool
    {
        return $this->isConfigured()
            && Http::asJson()->post($this->endpoint('deleteWebhook'))->successful();
    }

    /** Посилання для кнопки «Підключити Telegram». */
    public function deepLink(string $token): string
    {
        return sprintf('https://t.me/%s?start=%s', config('services.telegram.bot_username'), $token);
    }

    private function endpoint(string $method): string
    {
        return sprintf(
            '%s/bot%s/%s',
            rtrim((string) config('services.telegram.api_url'), '/'),
            config('services.telegram.bot_token'),
            $method,
        );
    }
}
