<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HelpController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth'])->group(function () {

    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/help', HelpController::class)->name('help');

    require __DIR__ . '/seniors.php';
    require __DIR__ . '/surveys.php';
    require __DIR__ . '/ml.php';
    require __DIR__ . '/reports.php';
    require __DIR__ . '/recommendations.php';
});

require __DIR__ . '/auth.php';
