<?php

use App\Http\Controllers\MlController;

Route::prefix('ml')->name('ml.')->middleware('no.time.limit')->group(function () {

    // All roles can view service status
    Route::middleware('role:admin,encoder,viewer')->group(function () {
        Route::get('/status', [MlController::class, 'status'])->name('status');
        Route::get('/batch/status', [MlController::class, 'batchStatus'])->name('batch.status');
        Route::get('/result/{senior}', [MlController::class, 'resultStatus'])->name('result.senior');
    });

    // Admin and encoder can run ML jobs. throttle:10,1 stops accidental
    // double-click storms / scripted abuse on these rare admin actions —
    // mirrors the throttle already used on api/gis/route-distance.
    Route::middleware(['role:admin,encoder', 'throttle:10,1'])->group(function () {
        Route::post('/start', [MlController::class, 'startServices'])->name('start');
        Route::post('/stop', [MlController::class, 'stopServices'])->name('stop');
        Route::post('/restart', [MlController::class, 'restartServices'])->name('restart');
        Route::get('/batch', [MlController::class, 'batchIndex'])->name('batch');
        Route::post('/batch/run', [MlController::class, 'batchRun'])->name('batch.run');
        Route::post('/run/{senior}', [MlController::class, 'runSingle'])->name('run.single');
    });
});
