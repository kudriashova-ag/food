<?php

namespace App\Enums;

enum MenuSectionType: string
{
    /** Набір страв, за замовчуванням відмічені всі; галочку можна зняти з будь-якої. */
    case Complex = 'complex';

    /** Взаємовиключні варіанти (суп / борщ) — можна обрати тільки один або пропустити. */
    case Choice = 'choice';

    /** Вода, випічка, солодощі — обираються незалежно й у довільній кількості. */
    case Extra = 'extra';

    public function label(): string
    {
        return match ($this) {
            self::Complex => 'Комплекс',
            self::Choice => 'Група вибору',
            self::Extra => 'Додаткові страви',
        };
    }
}
