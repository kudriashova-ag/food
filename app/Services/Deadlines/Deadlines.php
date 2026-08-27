<?php

namespace App\Services\Deadlines;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Пара дедлайнів для однієї пари «постачальник + дата харчування».
 *
 * null у orderAt/cancelAt означає, що правило на цей день тижня не налаштоване —
 * приймання вважається закритим, а постачальник має побачити попередження.
 */
final readonly class Deadlines
{
    public function __construct(
        public CarbonImmutable $serviceDate,
        public ?CarbonImmutable $orderAt,
        public ?CarbonImmutable $cancelAt,
        public bool $fromOverride = false,
    ) {}

    public function isConfigured(): bool
    {
        return $this->orderAt !== null;
    }

    public function orderingOpen(?CarbonInterface $now = null): bool
    {
        return $this->orderAt !== null
            && ($now ?? CarbonImmutable::now())->lessThan($this->orderAt);
    }

    public function cancellationOpen(?CarbonInterface $now = null): bool
    {
        return $this->cancelAt !== null
            && ($now ?? CarbonImmutable::now())->lessThan($this->cancelAt);
    }

    /** «Замовити можна до нд, 16.08, 09:00» */
    public function orderLabel(): string
    {
        if ($this->orderAt === null) {
            return 'Приймання замовлень не налаштоване';
        }

        return 'Замовити можна до '.$this->orderAt->translatedFormat('D, d.m, H:i');
    }

    /** «Скасувати до нд 09:00» */
    public function cancelLabel(): string
    {
        if ($this->cancelAt === null) {
            return 'Скасування недоступне';
        }

        return 'Скасувати до '.$this->cancelAt->translatedFormat('D, d.m, H:i');
    }
}
