<?php

use App\Http\Controllers\MlController;

Route::prefix('ml')->name('ml.')->group(function () {
    Route::get('/status',         [MlController::class, 'status'])->name('status');
    Route::post('/start',         [MlController::class, 'startServices'])->name('start');
    Route::get('/batch',          [MlController::class, 'batchIndex'])->name('batch');
    Route::post('/batch/run',     [MlController::class, 'batchRun'])->name('batch.run');
    Route::post('/run/{senior}',  [MlController::class, 'runSingle'])->name('run.single');
});
