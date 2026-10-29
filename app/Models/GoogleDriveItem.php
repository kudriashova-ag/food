<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Папка чи таблиця на Google Диску, створена сайтом: ключ → ID на Диску. */
class GoogleDriveItem extends Model
{
    protected $fillable = [
        'key',
        'drive_id',
    ];
}
