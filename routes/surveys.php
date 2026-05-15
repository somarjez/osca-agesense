<?php

use App\Http\Controllers\SurveyController;

Route::prefix('surveys')->name('surveys.')->group(function () {

    // Admin and encoder can manage surveys
    Route::middleware('role:admin,encoder')->group(function () {
        Route::get('/profile/create/{senior?}', [SurveyController::class, 'profileCreate'])->name('profile.create');

        Route::prefix('qol')->name('qol.')->group(function () {
            Route::get('/',                    [SurveyController::class, 'qolIndex'])->name('index');
            Route::get('/create/{senior}',     [SurveyController::class, 'qolCreate'])->name('create');
            Route::get('/{survey}/edit',       [SurveyController::class, 'qolEdit'])->name('edit');
            Route::get('/{survey}/results',    [SurveyController::class, 'qolResults'])->name('results');
        });
    });

    // Admin only: delete and restore surveys
    Route::prefix('qol')->name('qol.')->middleware('role:admin')->group(function () {
        Route::delete('/{survey}',         [SurveyController::class, 'qolDestroy'])->name('destroy');
        Route::post('/{id}/restore',       [SurveyController::class, 'qolRestore'])->name('restore');
    });
});
