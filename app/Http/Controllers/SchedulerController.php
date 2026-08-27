<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Планувальник через HTTP.
 *
 * Потрібен там, де cron уміє тільки «відкрити адресу за розкладом»,
 * а команду php виконати не може. Хостинг смикає цей маршрут раз на
 * хвилину — усередині запускається звичайний schedule:run.
 *
 * Захист простіший, ніж в обслуговуванні, бо нічого руйнівного
 * тут виконати не можна: тільки те, що вже описане в розкладі.
 */
class SchedulerController extends Controller
{
    public function __invoke(Request $request, string $token): mixed
    {
        abort_if(blank(config('school.scheduler_token')), 404);
        abort_unless(hash_equals((string) config('school.scheduler_token'), $token), 404);

        // Захист від паралельних запусків: якщо хостинг смикне адресу двічі,
        // друга спроба просто нічого не зробить.
        $lock = Cache::lock('scheduler:http', 55);

        if (! $lock->get()) {
            return response('Попередній запуск ще триває.', 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        try {
            Artisan::call('schedule:run');
            $output = Artisan::output();
        } finally {
            $lock->release();
        }

        return response($output ?: 'Завдань до виконання немає.', 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
    }
}
