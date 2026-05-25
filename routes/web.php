<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\DashboardController;
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
        Route::get('/',       [ActivityLogController::class, 'index'])->name('index');
        Route::delete('bulk', [ActivityLogController::class, 'bulkDestroy'])->name('bulk-destroy');
        Route::delete('all',  [ActivityLogController::class, 'clear'])->name('clear');
    });

    require __DIR__ . '/seniors.php';
    require __DIR__ . '/surveys.php';
    require __DIR__ . '/ml.php';
    require __DIR__ . '/reports.php';
    require __DIR__ . '/recommendations.php';
    require __DIR__ . '/users.php';
});

require __DIR__ . '/auth.php';
