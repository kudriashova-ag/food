<?php

namespace App\Enums;

enum OrderLineStatus: string
{
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активна',
            self::Cancelled => 'Скасована',
        };
    }
}
