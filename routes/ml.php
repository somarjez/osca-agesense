<?php

use App\Http\Controllers\MlController;

Route::prefix('ml')->name('ml.')->group(function () {

    // All roles can view service status
    Route::middleware('role:admin,encoder,viewer')->group(function () {
        Route::get('/status',           [MlController::class, 'status'])->name('status');
        Route::get('/batch/status',     [MlController::class, 'batchStatus'])->name('batch.status');
        Route::get('/result/{senior}',  [MlController::class, 'resultStatus'])->name('result.senior');
    });

    // Admin and encoder can run ML jobs
    Route::middleware('role:admin,encoder')->group(function () {
        Route::post('/start',           [MlController::class, 'startServices'])->name('start');
        Route::get('/batch',            [MlController::class, 'batchIndex'])->name('batch');
        Route::post('/batch/run',       [MlController::class, 'batchRun'])->name('batch.run');
        Route::post('/run/{senior}',    [MlController::class, 'runSingle'])->name('run.single');
    });
});
