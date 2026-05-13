<?php

namespace App\Providers;

use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Observers\ActivityLogObserver;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Override the built-in serve command so Python ML services
        // are auto-started whenever `php artisan serve` is run.
        $this->app->singleton('command.serve', function () {
            return new \App\Console\Commands\ServeCommand();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.custom');

        // Activity audit logging
        SeniorCitizen::observe(ActivityLogObserver::class);
        QolSurvey::observe(ActivityLogObserver::class);
        Recommendation::observe(ActivityLogObserver::class);
    }
}
