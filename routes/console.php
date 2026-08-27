<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Розклад
|--------------------------------------------------------------------------
|
| На сервері має бути один рядок crontab:
|   * * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
|
| Черга обробляється теж за розкладом — окремий воркер тримати не обов'язково
| (ТЗ, п. 15.2: розсилки виконуються за розкладом, не в момент HTTP-запиту).
|
*/

Schedule::command('queue:work --stop-when-empty --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('school:send-deadline-reminders')
    ->hourly()
    ->withoutOverlapping();

// Щохвилини, бо в кожного постачальника власний час розсилки.
Schedule::command('school:send-supplier-digests')
    ->everyMinute()
    ->withoutOverlapping();

// Скасування зводимо пачками, щоб карантин цілого класу не дав 20 повідомлень.
Schedule::command('school:send-cancellation-alerts')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// Прострочені токени прив'язки Telegram живуть 15 хвилин — прибираємо їх раз на добу.
Schedule::command('model:prune')->daily();
