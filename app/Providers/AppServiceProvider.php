<?php

namespace App\Providers;

use App\Services\Deadlines\DeadlineService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // scoped — щоб правила й винятки читалися з БД один раз на запит,
        // а не на кожен день у списку меню.
        $this->app->scoped(DeadlineService::class);

        $this->usePublicPathFromConfig();
    }

    /**
     * На хостингу, де document root змінити не можна, публічна папка лежить
     * поза проєктом. Задається через APP_PUBLIC_PATH у .env — інакше
     * storage:link і асети Filament пішли б не туди.
     *
     * Робиться саме тут, а не в bootstrap/app.php: там .env ще не прочитаний.
     */
    private function usePublicPathFromConfig(): void
    {
        $path = config('school.public_path');

        if (filled($path) && is_dir($path)) {
            $this->app->usePublicPath($path);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
