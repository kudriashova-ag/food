<?php

namespace App\Services\Telegram;

use App\Models\Student;
use App\Models\Supplier;
use App\Models\TelegramLink;
use App\Models\TelegramLinkToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Прив'язка Telegram (ТЗ, п. 12.2).
 *
 * Один механізм на два випадки: чат батьків прив'язується до учня,
 * чат кухні — до постачальника. Токен одноразовий і живе 15 хвилин.
 */
class TelegramLinkService
{
    public const TOKEN_TTL_MINUTES = 15;

    public function __construct(private readonly TelegramClient $client) {}

    public function issueToken(Student|Supplier $owner): TelegramLinkToken
    {
        $key = $this->ownerKey($owner);

        // Старі невикористані токени гасимо, щоб чинним лишався один.
        TelegramLinkToken::query()
            ->where($key, $owner->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return TelegramLinkToken::create([
            $key => $owner->id,
            'token' => Str::random(32),
            'expires_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
        ]);
    }

    public function deepLinkFor(Student|Supplier $owner): string
    {
        return $this->client->deepLink($this->issueToken($owner)->token);
    }

    /**
     * Обробка /start <token> з приватного чату.
     *
     * @return TelegramLink|null  null, якщо токен недійсний
     */
    public function connect(string $token, string $chatId, ?string $username = null): ?TelegramLink
    {
        $record = TelegramLinkToken::query()->where('token', $token)->first();

        if ($record === null || ! $record->isUsable()) {
            return null;
        }

        $record->update(['used_at' => now()]);

        $owner = $record->supplier_id !== null
            ? ['supplier_id' => $record->supplier_id]
            : ['student_id' => $record->student_id];

        $link = TelegramLink::query()->updateOrCreate(
            [...$owner, 'chat_id' => $chatId],
            [
                'username' => $username,
                'is_active' => true,
                'linked_at' => now(),
                'deactivated_at' => null,
            ],
        );

        $this->client->sendMessage($chatId, $this->welcomeText($link));

        return $link;
    }

    public function disconnect(TelegramLink $link): void
    {
        $link->delete();
    }

    private function welcomeText(TelegramLink $link): string
    {
        if ($link->isSupplierChat()) {
            return "Готово. Сюди приходитимуть зведення замовлень на завтра та зміни після них.\n"
                .'Кнопки для запиту зведення — нижче.';
        }

        return 'Готово. Тепер сюди приходитимуть сповіщення про замовлення харчування.';
    }

    private function ownerKey(Model $owner): string
    {
        return $owner instanceof Supplier ? 'supplier_id' : 'student_id';
    }
}
