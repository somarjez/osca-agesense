<?php

use App\Http\Controllers\RecommendationController;

Route::prefix('recommendations')->name('recommendations.')->group(function () {

    // All roles can view
    Route::middleware('role:admin,encoder,viewer')->group(function () {
        Route::get('/',         [RecommendationController::class, 'index'])->name('index');
        Route::get('/{senior}', [RecommendationController::class, 'show'])->name('show');
    });

    // Admin and encoder can update status and assign
    Route::middleware('role:admin,encoder')->group(function () {
        Route::patch('/{rec}/status', [RecommendationController::class, 'updateStatus'])->name('status');
        Route::patch('/{rec}/assign', [RecommendationController::class, 'assign'])->name('assign');
    });
});
