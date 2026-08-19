<?php

namespace App\Providers;

use App\Console\Commands\ServeCommand;
use App\Models\QolSurvey;
use App\Models\Recommendation;
use App\Models\SeniorCitizen;
use App\Observers\ActivityLogObserver;
use App\Observers\MlResultStalenessObserver;
use App\Observers\SeniorDataVersionObserver;
use App\Observers\SeniorLocationObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        // Barangay-derived state sync — when a senior's barangay changes,
        // clears their now-stale generated map coordinates, marks the ML
        // result stale, bumps the GIS/dashboard cache version, and queues a
        // targeted re-geocode. See SeniorLocationObserver for details.
        SeniorCitizen::observe(SeniorLocationObserver::class);

        // Login rate limiting — 5 attempts/minute per email+IP combination,
        // so an attacker can't lock out a legitimate user by spamming their
        // email from a different IP, nor brute-force one IP across many emails.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email').'|'.$request->ip());
        });

        // Temporary perf diagnostic — logs cumulative DB query time and
        // query count per request, so a slow request can be split into
        // "DB-bound" vs "everything else" (framework boot, view/Livewire
        // render). Needed because Neon's own dashboard only sees each
        // query's server-side execution time, not what share of a request's
        // wall-clock time (nginx's rt= in the access log) that DB time
        // actually accounts for — the two prior production perf PRs
        // (prefetch-storm fix, then PgBouncer pooling) each measurably
        // helped, but pages are still taking several seconds, and this is
        // the next piece of evidence needed to tell whether the remaining
        // cost is the database or PHP execution itself. Cheap (one closure
        // per query incrementing two counters, one log line at request end)
        // and meant to be temporary — remove once diagnosed. Skips /up
        // (Render's health check, polled constantly) to avoid log noise.
        if (! $this->app->runningUnitTests() && ! $this->app->runningInConsole()) {
            $queryTimeMs = 0.0;
            $queryCount = 0;

            DB::listen(function ($query) use (&$queryTimeMs, &$queryCount) {
                $queryTimeMs += $query->time;
                $queryCount++;
            });

            $this->app->terminating(function () use (&$queryTimeMs, &$queryCount) {
                if (request()->is('up')) {
                    return;
                }

                Log::info('perf', [
                    'path' => request()->path(),
                    'query_ms' => round($queryTimeMs, 1),
                    'query_count' => $queryCount,
                ]);
            });
        }

        // TC-DEP-06 — login/logout/failed-login audit trail
        // (App\Listeners\LogAuthenticationActivity). Deliberately NOT
        // registered here via Event::listen() — Laravel 11's event
        // auto-discovery already finds every public method in
        // app/Listeners/* that type-hints a single event parameter
        // (handleLogin/handleLogout/handleFailed all qualify) and wires
        // each one correctly on its own. An earlier version of this file
        // ALSO registered them explicitly here, which didn't override
        // auto-discovery — it stacked with it, so every login/logout/
        // failed-login was logged twice (confirmed via `php artisan
        // event:list` showing two listener entries per event, and two
        // activity_logs rows per single request). Do not re-add explicit
        // registration for this listener.
    }
}
