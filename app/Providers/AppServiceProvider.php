<?php

namespace App\Providers;

use App\Console\Commands\ServeCommand;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Observers\ActivityLogObserver;
use App\Observers\MlResultStalenessObserver;
use App\Observers\SeniorDataVersionObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\RateLimiter;
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
            return new ServeCommand;
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

        // ML result staleness — marks cached results stale when profile or QoL data changes
        SeniorCitizen::observe(MlResultStalenessObserver::class);
        QolSurvey::observe(MlResultStalenessObserver::class);

        // Dashboard/GIS/cluster cache invalidation — bumps the version stamp
        // folded into those caches' keys when the active-senior set changes
        SeniorCitizen::observe(SeniorDataVersionObserver::class);

        // Login rate limiting — 5 attempts/minute per email+IP combination,
        // so an attacker can't lock out a legitimate user by spamming their
        // email from a different IP, nor brute-force one IP across many emails.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email').'|'.$request->ip());
        });
    }
}
