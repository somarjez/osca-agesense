<?php

use App\Http\Controllers\ReportController;

Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/cluster',                [ReportController::class, 'cluster'])->name('cluster');
    Route::get('/gis',                    [ReportController::class, 'gis'])->name('gis');
    Route::get('/risk',                   [ReportController::class, 'risk'])->name('risk');
    Route::get('/risk/export',            [ReportController::class, 'exportRisk'])->name('risk.export');
    Route::get('/cluster/export',         [ReportController::class, 'exportCluster'])->name('cluster.export');
    Route::post('/cluster/snapshot',      [ReportController::class, 'snapshotClusters'])->name('cluster.snapshot');
    Route::get('/registry/export',        [ReportController::class, 'exportRegistry'])->name('registry.export');
    Route::get('/barangay',               [ReportController::class, 'barangayIndex'])->name('barangay.index');
    Route::get('/barangay/{brgy}',        [ReportController::class, 'barangay'])->name('barangay');
});
