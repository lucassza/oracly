<?php

namespace App\Providers;

use App\Oracly\Contracts\DailyMatchesProvider;
use App\Oracly\Services\TodayMatchService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DailyMatchesProvider::class, TodayMatchService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
