<?php

use App\Http\Controllers\RecommendationController;

Route::prefix('recommendations')->name('recommendations.')->group(function () {
    Route::get('/',                   [RecommendationController::class, 'index'])->name('index');
    Route::get('/{senior}',           [RecommendationController::class, 'show'])->name('show');
    Route::patch('/{rec}/status',     [RecommendationController::class, 'updateStatus'])->name('status');
    Route::patch('/{rec}/assign',     [RecommendationController::class, 'assign'])->name('assign');
});
