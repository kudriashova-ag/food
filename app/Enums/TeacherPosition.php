<?php

namespace App\Enums;

enum TeacherPosition: string
{
    case Curator = 'curator';
    case Director = 'director';

    public function label(): string
    {
        return match ($this) {
            self::Curator => 'Куратор',
            self::Director => 'Директор',
        };
    }

    /** @return array<string, string> значення => назва, для списків вибору */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $position): array => [$position->value => $position->label()])
            ->all();
    }
}
