<?php

use App\Http\Controllers\ReportController;

Route::prefix('reports')->name('reports.')->group(function () {
    Route::get('/cluster',         [ReportController::class, 'cluster'])->name('cluster');
    Route::get('/risk',            [ReportController::class, 'risk'])->name('risk');
    Route::get('/risk/export',     [ReportController::class, 'riskExport'])->name('risk.export');
    Route::get('/cluster/export',  [ReportController::class, 'clusterExport'])->name('cluster.export');
    Route::get('/barangay/{brgy}', [ReportController::class, 'barangay'])->name('barangay');
});
