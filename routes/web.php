<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GisApiController;
use App\Http\Controllers\HelpController;
use App\Services\MlService;
use App\Support\SeniorDataVersion;
use App\Support\SingleSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

// Guests skip the /dashboard bounce and land straight on the sign-in screen —
// one 302 instead of two, which matters on cold starts.
Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'))
    ->name('home');

Route::middleware(['auth'])->group(function () {

    // All authenticated roles
    Route::middleware('role:admin,encoder,viewer')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');

        // Cheap freshness check for the dashboard — a single Cache::get, no
        // DB query. Polled every ~15-20s by main-dashboard.blade.php so it
        // can trigger a real Livewire refresh the moment ML results actually
        // change, instead of waiting on the much wider wire:poll.300s
        // backstop (kept wide deliberately — see that poll's own comment —
        // to avoid the 6 dashboard charts re-animating on every tick).
        Route::get('/dashboard/version-check', fn () => response()->json([
            'version' => SeniorDataVersion::current(),
        ]))->name('dashboard.version-check');

        // Polled every ~20s by the currently-active session (resources/js/
        // login-attempt-watch.js) so it can be notified when someone else
        // tries to sign in to the same account while it's blocked by
        // SingleSession — see routes/auth.php's blocked-login branch, which
        // is the only place that ever writes this cache entry. Read-and-clear
        // (see SingleSession::pullAttempt) so it's only ever surfaced once.
        Route::get('/account/login-attempt-check', fn () => response()->json([
            'attempt' => SingleSession::pullAttempt(auth()->id()),
        ]))->name('account.login-attempt-check');

        Route::get('/help', HelpController::class)->name('help');

        // Cached ML nav-health status polled by the shared client-side poller
        // (resources/js/ml-health.js), which every status surface listens to.
        // 30s TTL while online; 5s while down/degraded — kept short so the
        // 10s client poll (ml-health.js's INTERVAL_DOWN_MS) isn't waiting on a
        // stale server cache to notice a recovery. This used to be 15s, which
        // combined with the client's own polling to leave services showing
        // red for up to ~45-60s after actually coming back — see PR history
        // for the original "stays red until refresh" bug this closes.
        Route::get('/ml/nav-health', function () {
            $health = Cache::get('ml_nav_health');
            if ($health === null) {
                try {
                    $health = app(MlService::class)->healthCheck();
                } catch (Throwable) {
                    $health = ['preprocessor' => 'unreachable', 'inference' => 'unreachable', 'local_runner' => 'unavailable', 'mode' => 'php_fallback'];
                }
                $ttl = ($health['preprocessor'] === 'ok' && $health['inference'] === 'ok') ? 30 : 5;
                Cache::put('ml_nav_health', $health, $ttl);
            }
            $dot = match (true) {
                $health['preprocessor'] === 'ok' && $health['inference'] === 'ok' => 'ok',
                ($health['local_runner'] ?? null) === 'available' => 'warn',
                default => 'err',
            };
            $warming = in_array('warming', [
                $health['preprocessor'] ?? null,
                $health['inference'] ?? null,
            ], true);
            $title = match (true) {
                $dot === 'ok' => 'HTTP services online',
                $warming => 'Analysis services are warming up',
                $dot === 'warn' => 'HTTP services offline — using local fallback',
                default => 'All analysis services unavailable',
            };

            return response()->json([
                'dot' => $dot,
                'title' => $title,
                'services' => [
                    'preprocessor' => $health['preprocessor'] ?? 'unreachable',
                    'inference' => $health['inference'] ?? 'unreachable',
                ],
            ]);
        })->name('ml.nav-health');
    });

    // Admin only
    Route::middleware('role:admin')->prefix('activity-log')->name('activity-log.')->group(function () {
        Route::get('/', [ActivityLogController::class, 'index'])->name('index');
        Route::delete('bulk', [ActivityLogController::class, 'bulkDestroy'])->name('bulk-destroy');
        Route::delete('all', [ActivityLogController::class, 'clear'])->name('clear');
    });

    require __DIR__.'/seniors.php';
    require __DIR__.'/surveys.php';
    require __DIR__.'/ml.php';
    require __DIR__.'/reports.php';
    require __DIR__.'/recommendations.php';
    require __DIR__.'/users.php';

    // GIS data API — called via JS fetch from the GIS report view.
    // Must stay in the web (session) middleware group so browser auth works.
    Route::middleware('role:admin,encoder,viewer')
        ->prefix('api/gis')
        ->name('api.gis.')
        ->group(function () {
            Route::get('/seniors', [GisApiController::class, 'seniors'])->name('seniors');
            Route::get('/facilities', [GisApiController::class, 'facilities'])->name('facilities');
            Route::get('/boundary/pagsanjan', [GisApiController::class, 'pagsanjanBoundary'])->name('boundary.pagsanjan');
            Route::get('/boundary/barangays', [GisApiController::class, 'barangayBoundaries'])->name('boundary.barangays');
            Route::get('/route-distance', [GisApiController::class, 'routeDistance'])
                ->middleware('throttle:60,1')
                ->name('route-distance');
        });
});

require __DIR__.'/auth.php';
