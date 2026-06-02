<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GisApiController;
use App\Http\Controllers\HelpController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth'])->group(function () {

    // All authenticated roles
    Route::middleware('role:admin,encoder,viewer')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/help', HelpController::class)->name('help');
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
            Route::get('/route-distance', [GisApiController::class, 'routeDistance'])->name('route-distance');
        });
});

require __DIR__.'/auth.php';
