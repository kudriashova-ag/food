<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/** Кидається, коли дію намагаються виконати після дедлайну. Тексти — з ТЗ, п. 7.4. */
class DeadlinePassedException extends RuntimeException
{
    public static function ordering(CarbonInterface $serviceDate): self
    {
        return new self(sprintf(
            'Приймання замовлень на %s завершено.',
            $serviceDate->translatedFormat('d.m.Y'),
        ));
    }

    public static function cancellation(CarbonInterface $serviceDate): self
    {
        return new self(sprintf(
            'Скасування замовлення на %s уже недоступне. Термін минув.',
            $serviceDate->translatedFormat('d.m.Y'),
        ));
    }
}
