<?php

namespace App\Http\Controllers;

use App\Services\Telegram\SupplierBotHandler;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Вебхук бота. Секрет входить в адресу, тож стороннім вона невідома.
 * Бот працює тільки в приватних чатах — повідомлення з груп ігноруються (ТЗ, п. 12.2).
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $secret,
        TelegramLinkService $links,
        TelegramClient $client,
        SupplierBotHandler $supplierBot,
    ): JsonResponse {
        abort_unless(
            filled(config('services.telegram.webhook_secret'))
                && hash_equals((string) config('services.telegram.webhook_secret'), $secret),
            404,
        );

        if ($request->has('callback_query')) {
            return $this->handleCallback($request, $supplierBot);
        }

        $message = $request->input('message', []);
        $chat = $message['chat'] ?? [];

        if (($chat['type'] ?? null) !== 'private') {
            return $this->ok();
        }

        $chatId = (string) ($chat['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === '' || $text === '') {
            return $this->ok();
        }

        if (str_starts_with($text, '/start')) {
            return $this->handleStart($text, $chatId, $chat, $links, $client, $supplierBot);
        }

        // Кнопки й дати в чаті кухні.
        $supplierBot->handleText($chatId, $text);

        return $this->ok();
    }

    /** @param array<string, mixed> $chat */
    private function handleStart(
        string $text,
        string $chatId,
        array $chat,
        TelegramLinkService $links,
        TelegramClient $client,
        SupplierBotHandler $supplierBot,
    ): JsonResponse {
        $token = trim(substr($text, strlen('/start')));

        if ($token === '') {
            // Уже прив'язаний чат кухні — показуємо кнопки замість підказки.
            if ($supplierBot->isSupplierChat($chatId)) {
                $supplierBot->sendMenu($chatId);

                return $this->ok();
            }

            $client->sendMessage($chatId, 'Щоб підключити сповіщення, натисніть кнопку «Підключити Telegram» у кабінеті.');

            return $this->ok();
        }

        $link = $links->connect($token, $chatId, $chat['username'] ?? null);

        if ($link === null) {
            $client->sendMessage($chatId, 'Посилання вже використане або застаріле. Згенеруйте нове в кабінеті.');

            return $this->ok();
        }

        if ($link->isSupplierChat()) {
            $supplierBot->sendMenu($chatId, 'Оберіть, за яку дату показати замовлення:');
        }

        return $this->ok();
    }

    private function handleCallback(Request $request, SupplierBotHandler $supplierBot): JsonResponse
    {
        $callback = $request->input('callback_query', []);
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $callbackId = (string) ($callback['id'] ?? '');

        if ($chatId !== '' && $data !== '' && $callbackId !== '') {
            $supplierBot->handleCallback($chatId, $data, $callbackId);
        }

        return $this->ok();
    }

    private function ok(): JsonResponse
    {
        return response()->json(['ok' => true]);
    }
}
