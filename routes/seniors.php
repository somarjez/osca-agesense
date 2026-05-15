<?php

use App\Http\Controllers\SeniorCitizenController;

Route::prefix('seniors')->name('seniors.')->group(function () {

    // All authenticated users can view
    Route::middleware('role:admin,encoder,viewer')->group(function () {
        Route::get('/',             [SeniorCitizenController::class, 'index'])->name('index');
        Route::get('/{senior}',     [SeniorCitizenController::class, 'show'])->name('show');
        Route::get('/{senior}/export', [SeniorCitizenController::class, 'export'])->name('export');
    });

    // Admin and encoder can create and edit
    Route::middleware('role:admin,encoder')->group(function () {
        Route::get('/create',       [SeniorCitizenController::class, 'create'])->name('create');
        Route::post('/',            [SeniorCitizenController::class, 'store'])->name('store');
        Route::get('/{senior}/edit', [SeniorCitizenController::class, 'edit'])->name('edit');
        Route::put('/{senior}',     [SeniorCitizenController::class, 'update'])->name('update');
    });

    // Admin only: delete, archive, restore, force-delete
    Route::middleware('role:admin')->group(function () {
        Route::delete('/{senior}',          [SeniorCitizenController::class, 'destroy'])->name('destroy');
        Route::get('/archives',             [SeniorCitizenController::class, 'archives'])->name('archives');
        Route::post('/{id}/restore',        [SeniorCitizenController::class, 'restore'])->name('restore');
        Route::delete('/{id}/force-delete', [SeniorCitizenController::class, 'forceDestroy'])->name('force-delete');
    });
});
