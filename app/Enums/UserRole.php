<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Supplier = 'supplier';
    case Student = 'student';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Адміністратор школи',
            self::Supplier => 'Постачальник',
            self::Student => 'Учень',
        };
    }
}
