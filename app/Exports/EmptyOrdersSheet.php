<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;

/** Заглушка, коли за період немає жодного замовлення — файл не може бути без жодного аркуша. */
class EmptyOrdersSheet implements FromArray, WithTitle
{
    public function title(): string
    {
        return 'Немає замовлень';
    }

    public function array(): array
    {
        return [['За обраний період замовлень немає.']];
    }
}
