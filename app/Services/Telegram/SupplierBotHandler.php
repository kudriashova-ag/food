<?php

namespace App\Services\Telegram;

use App\Models\TelegramLink;
use App\Services\Reports\SupplierDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Кнопки «Отримати замовлення» в чаті постачальника.
 *
 * Працює лише в чатах, прив'язаних до постачальника: батьківський чат
 * такого запиту не отримає й даних інших постачальників не побачить.
 */
class SupplierBotHandler
{
    /** Скільки чекаємо на введення дати після натискання «Інша дата». */
    private const AWAITING_DATE_TTL_MINUTES = 5;

    public function __construct(
        private readonly TelegramClient $client,
        private readonly SupplierDigestService $digests,
    ) {}

    public function isSupplierChat(string $chatId): bool
    {
        return $this->linkFor($chatId) !== null;
    }

    /** Головне меню з кнопками. */
    public function sendMenu(string $chatId, ?string $text = null): void
    {
        $this->client->sendMessage(
            $chatId,
            $text ?? 'Оберіть, за яку дату показати замовлення:',
            $this->keyboard(),
        );
    }

    /** Обробка натискання кнопки. */
    public function handleCallback(string $chatId, string $data, string $callbackQueryId): void
    {
        $this->client->answerCallbackQuery($callbackQueryId);

        $link = $this->linkFor($chatId);

        if ($link === null) {
            return;
        }

        if ($data === 'digest:pick') {
            Cache::put($this->awaitingKey($chatId), true, now()->addMinutes(self::AWAITING_DATE_TTL_MINUTES));

            $this->client->sendMessage($chatId, 'Надішліть дату у форматі <b>17.08</b> або <b>17.08.2026</b>.');

            return;
        }

        $date = match ($data) {
            'digest:today' => CarbonImmutable::today(),
            'digest:tomorrow' => CarbonImmutable::tomorrow(),
            default => null,
        };

        if ($date !== null) {
            $this->reply($chatId, $link, $date);
        }
    }

    /**
     * Текстове повідомлення в чаті постачальника.
     *
     * @return bool  чи обробили повідомлення
     */
    public function handleText(string $chatId, string $text): bool
    {
        $link = $this->linkFor($chatId);

        if ($link === null) {
            return false;
        }

        if ($text === '/start' || $text === '/menu' || mb_strtolower($text) === 'замовлення') {
            $this->sendMenu($chatId);

            return true;
        }

        if (! Cache::pull($this->awaitingKey($chatId))) {
            return false;
        }

        $date = $this->parseDate($text);

        if ($date === null) {
            $this->client->sendMessage($chatId, 'Не розпізнав дату. Спробуйте ще раз: <b>17.08</b>.', $this->keyboard());

            return true;
        }

        $this->reply($chatId, $link, $date);

        return true;
    }

    private function reply(string $chatId, TelegramLink $link, CarbonImmutable $date): void
    {
        $this->client->sendMessage(
            $chatId,
            $this->digests->textFor($link->supplier, $date),
            $this->keyboard(),
        );
    }

    /** «17.08» або «17.08.2026». Без року — беремо найближчий рік. */
    private function parseDate(string $text): ?CarbonImmutable
    {
        $text = trim($text);

        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $text, $m)) {
            return CarbonImmutable::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
        }

        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})$/', $text, $m)) {
            $date = CarbonImmutable::createFromDate(now()->year, (int) $m[2], (int) $m[1])->startOfDay();

            // Дата глибоко в минулому — швидше за все мають на увазі наступний рік.
            return $date->lessThan(CarbonImmutable::today()->subMonths(6))
                ? $date->addYear()
                : $date;
        }

        return null;
    }

    /** @return array<int, array<int, array<string, string>>> */
    private function keyboard(): array
    {
        return [
            [
                ['text' => 'Сьогодні', 'callback_data' => 'digest:today'],
                ['text' => 'Завтра', 'callback_data' => 'digest:tomorrow'],
            ],
            [
                ['text' => 'Інша дата', 'callback_data' => 'digest:pick'],
            ],
        ];
    }

    private function linkFor(string $chatId): ?TelegramLink
    {
        return TelegramLink::query()
            ->where('chat_id', $chatId)
            ->whereNotNull('supplier_id')
            ->active()
            ->with('supplier')
            ->first();
    }

    private function awaitingKey(string $chatId): string
    {
        return "telegram:awaiting-date:{$chatId}";
    }
}
