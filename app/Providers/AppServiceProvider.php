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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
