<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GisApiController;
use App\Http\Controllers\HelpController;
use App\Services\MlService;
use App\Support\SeniorDataVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

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

        Route::get('/help', HelpController::class)->name('help');

        // Cached ML nav-health status for the topbar dot, fetched async after
        // paint. Reuses the same `ml_nav_health` cache key and 30s(online)/
        // 15s(offline) TTL as the inline check in layouts/app.blade.php.
        Route::get('/ml/nav-health', function () {
            $health = Cache::get('ml_nav_health');
            if ($health === null) {
                try {
                    $health = app(MlService::class)->healthCheck();
                } catch (Throwable) {
                    $health = ['preprocessor' => 'unreachable', 'inference' => 'unreachable', 'local_runner' => 'unavailable', 'mode' => 'php_fallback'];
                }
                $ttl = ($health['preprocessor'] === 'ok' && $health['inference'] === 'ok') ? 30 : 15;
                Cache::put('ml_nav_health', $health, $ttl);
            }
            $dot = match (true) {
                $health['preprocessor'] === 'ok' && $health['inference'] === 'ok' => 'ok',
                ($health['local_runner'] ?? null) === 'available' => 'warn',
                default => 'err',
            };
            $title = match ($dot) {
                'ok' => 'HTTP services online',
                'warn' => 'HTTP services offline — using local fallback',
                default => 'All analysis services unavailable',
            };

            return response()->json(['dot' => $dot, 'title' => $title]);
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
