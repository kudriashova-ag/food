<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/** Файл із логінами й паролями для роздачі класним керівникам (ТЗ, п. 3.1). */
class StudentCredentialsExport implements Export, FromArray, ShouldAutoSize, WithHeadings
{
    use Exportable;

    public function __construct(private readonly Collection $credentials) {}

    public function headings(): array
    {
        return ['ПІБ', 'Клас', 'Логін', 'Пароль'];
    }

    public function array(): array
    {
        return $this->credentials
            ->map(fn (array $row): array => [
                $row['full_name'],
                $row['class'],
                $row['login'],
                $row['password'],
            ])
            ->all();
    }
}
