<?php

use App\Http\Controllers\ModelValidationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\XaiController;

Route::prefix('reports')->name('reports.')->group(function () {

    // All roles can view reports
    Route::middleware('role:admin,encoder,viewer')->group(function () {
        Route::get('/cluster', [ReportController::class, 'cluster'])->name('cluster');
        Route::get('/gis', [ReportController::class, 'gis'])->name('gis');
        Route::get('/risk', [ReportController::class, 'risk'])->name('risk');
        Route::get('/barangay', [ReportController::class, 'barangayIndex'])->name('barangay.index');
        Route::get('/barangay/{brgy}', [ReportController::class, 'barangay'])->name('barangay');
        Route::get('/xai/model-insights', [XaiController::class, 'modelInsights'])->name('xai.model-insights');
    });

    // Admin only: exports and snapshots. no.time.limit matches routes/ml.php's
    // convention for actions that legitimately run longer than PHP's default
    // max_execution_time (this is the confirmed cause of the reported 30s
    // "Maximum execution time exceeded" errors on report exports).
    Route::middleware(['role:admin', 'no.time.limit'])->group(function () {
        Route::get('/risk/export', [ReportController::class, 'exportRisk'])->name('risk.export');
        Route::get('/cluster/export', [ReportController::class, 'exportCluster'])->name('cluster.export');
        Route::get('/gis/export', [ReportController::class, 'exportGis'])->name('gis.export');
        Route::post('/gis/geocode', [ReportController::class, 'runGisGeocode'])->name('gis.geocode');
        Route::post('/cluster/snapshot', [ReportController::class, 'snapshotClusters'])->name('cluster.snapshot');
        Route::delete('/cluster/snapshot/{date}', [ReportController::class, 'destroySnapshot'])->name('cluster.snapshot.destroy');
        Route::get('/registry', [ReportController::class, 'registryIndex'])->name('registry');
        Route::get('/registry/export', [ReportController::class, 'exportRegistry'])->name('registry.export');

        // System Validation — model evaluation evidence (admin only)
        Route::get('/validation', [ModelValidationController::class, 'index'])->name('validation');
        Route::get('/validation/plot/{name}', [ModelValidationController::class, 'plot'])->name('validation.plot');
    });
});
