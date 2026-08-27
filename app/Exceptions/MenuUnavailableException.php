<?php

namespace App\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/** Страву неможливо замовити: меню не опубліковане, день неробочий або страви в ньому немає. */
class MenuUnavailableException extends RuntimeException
{
    public static function dayNotAvailable(CarbonInterface $date): self
    {
        return new self(sprintf('Меню на %s недоступне.', $date->translatedFormat('d.m.Y')));
    }

    public static function dishNotInMenu(string $dishName, CarbonInterface $date): self
    {
        return new self(sprintf('Страви «%s» немає в меню на %s.', $dishName, $date->translatedFormat('d.m.Y')));
    }

    public static function outsideHorizon(): self
    {
        return new self(sprintf(
            'Замовлення приймаються не більш ніж на %d днів уперед.',
            config('school.menu_horizon_days'),
        ));
    }
}
