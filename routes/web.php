<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MlController;
use App\Http\Controllers\RecommendationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SeniorCitizenController;
use App\Http\Controllers\SurveyController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware(['auth'])->group(function () {

    // ── Dashboard
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // ── Senior Citizens
    Route::prefix('seniors')->name('seniors.')->group(function () {
        Route::get('/',              [SeniorCitizenController::class, 'index'])->name('index');
        Route::get('/create',        [SeniorCitizenController::class, 'create'])->name('create');
        Route::post('/',             [SeniorCitizenController::class, 'store'])->name('store');
        Route::get('/{senior}',      [SeniorCitizenController::class, 'show'])->name('show');
        Route::get('/{senior}/edit', [SeniorCitizenController::class, 'edit'])->name('edit');
        Route::put('/{senior}',      [SeniorCitizenController::class, 'update'])->name('update');
        Route::delete('/{senior}',   [SeniorCitizenController::class, 'destroy'])->name('destroy');
        Route::get('/{senior}/export', [SeniorCitizenController::class, 'export'])->name('export');
    });

    // ── Surveys
    Route::prefix('surveys')->name('surveys.')->group(function () {
        Route::get('/profile/create/{senior?}', [SurveyController::class, 'profileCreate'])->name('profile.create');
        Route::prefix('qol')->name('qol.')->group(function () {
            Route::get('/',                [SurveyController::class, 'qolIndex'])->name('index');
            Route::get('/create/{senior}', [SurveyController::class, 'qolCreate'])->name('create');
            Route::get('/{survey}/edit',   [SurveyController::class, 'qolEdit'])->name('edit');
            Route::get('/{survey}/results',[SurveyController::class, 'qolResults'])->name('results');
        });
    });

    // ── ML Services
    Route::prefix('ml')->name('ml.')->group(function () {
        Route::get('/status',         [MlController::class, 'status'])->name('status');
        Route::get('/batch',          [MlController::class, 'batchIndex'])->name('batch');
        Route::post('/batch/run',     [MlController::class, 'batchRun'])->name('batch.run');
        Route::post('/run/{senior}',  [MlController::class, 'runSingle'])->name('run.single');
    });

    // ── Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/cluster',        [ReportController::class, 'cluster'])->name('cluster');
        Route::get('/risk',           [ReportController::class, 'risk'])->name('risk');
        Route::get('/risk/export',    [ReportController::class, 'riskExport'])->name('risk.export');
        Route::get('/cluster/export', [ReportController::class, 'clusterExport'])->name('cluster.export');
        Route::get('/barangay/{brgy}',[ReportController::class, 'barangay'])->name('barangay');
    });

    // ── Recommendations
    Route::prefix('recommendations')->name('recommendations.')->group(function () {
        Route::get('/',                   [RecommendationController::class, 'index'])->name('index');
        Route::get('/{senior}',           [RecommendationController::class, 'show'])->name('show');
        Route::patch('/{rec}/status',     [RecommendationController::class, 'updateStatus'])->name('status');
        Route::patch('/{rec}/assign',     [RecommendationController::class, 'assign'])->name('assign');
    });
});

require __DIR__ . '/auth.php';
