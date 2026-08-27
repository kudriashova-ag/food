<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\Import;

/**
 * Порожній імпорт: розбір рядків робимо самі в StudentImportService,
 * а пакету потрібен лише об'єкт для Excel::toArray().
 */
class RawRowsImport implements Import
{
}
