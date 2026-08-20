<?php

use App\Http\Controllers\BulkUploadController;
use App\Http\Controllers\SeniorCitizenController;

Route::prefix('seniors')->name('seniors.')->group(function () {

    // Literal paths must come before wildcard routes
    Route::middleware('role:admin,encoder')->group(function () {
        Route::get('/create', [SeniorCitizenController::class, 'create'])->name('create');
        Route::post('/', [SeniorCitizenController::class, 'store'])->name('store');
        Route::get('/bulk-upload/sample', [BulkUploadController::class, 'sample'])->name('bulk-upload.sample');
        // Polled by seniors/index.blade.php to show insert-phase progress
        // after a page reload/return — see BulkUploadController::status().
        Route::get('/bulk-upload/status', [BulkUploadController::class, 'status'])->name('bulk-upload.status');
        // Clears the same status marker on dismiss — see
        // BulkUploadController::dismissStatus().
        Route::post('/bulk-upload/status/dismiss', [BulkUploadController::class, 'dismissStatus'])->name('bulk-upload.status.dismiss');
    });

    Route::middleware(['role:admin,encoder', 'no.time.limit'])->group(function () {
        Route::post('/bulk-upload', [BulkUploadController::class, 'upload'])->name('bulk-upload');
    });

    Route::middleware('role:admin')->group(function () {
        Route::get('/archives', [SeniorCitizenController::class, 'archives'])->name('archives');
        Route::post('/{id}/restore', [SeniorCitizenController::class, 'restore'])->name('restore');
        Route::delete('/{id}/force-delete', [SeniorCitizenController::class, 'forceDestroy'])->name('force-delete');
        Route::post('/bulk-archive', [SeniorCitizenController::class, 'bulkDestroy'])->name('bulk-archive');
        Route::post('/bulk-restore', [SeniorCitizenController::class, 'bulkRestore'])->name('bulk-restore');
        Route::post('/bulk-delete', [SeniorCitizenController::class, 'bulkForceDestroy'])->name('bulk-delete');
    });

    // In-progress "New Profile" drafts (senior_citizen_id is null — never an active senior).
    // Literal path, must stay before the /{senior} wildcard below, same as /deceased.
    Route::middleware('role:admin,encoder')->prefix('drafts')->name('drafts.')->group(function () {
        Route::get('/', [SeniorCitizenController::class, 'draftsIndex'])->name('index');
        Route::delete('/{draft}', [SeniorCitizenController::class, 'draftsDestroy'])->name('destroy');
    });

    // Wildcard routes below
    Route::middleware('role:admin,encoder,viewer')->group(function () {
        Route::get('/', [SeniorCitizenController::class, 'index'])->name('index');
        // Literal path — must stay before the /{senior} wildcard below so it wins.
        Route::get('/deceased', [SeniorCitizenController::class, 'deceasedIndex'])->name('deceased');
        Route::get('/{senior}', [SeniorCitizenController::class, 'show'])->name('show');
    });

    Route::middleware('role:admin,encoder')->group(function () {
        // Edit page renders the Livewire ProfileSurvey component, which POSTs directly
        // via Livewire's own network layer — no PUT route is needed.
        Route::get('/{senior}/edit', [SeniorCitizenController::class, 'edit'])->name('edit');

        // PDF export — admin/encoder only (TC-SEC-07). Was previously grouped
        // with the read-only role:admin,encoder,viewer routes above; moved
        // here to match SeniorCitizenPolicy::export()'s narrowed gate — the
        // exported PDF contains the full, unredacted profile/ML/QoL data,
        // unlike the deliberately generalized GPS the viewer role gets on
        // the GIS map (CoordinatePrivacy).
        Route::get('/{senior}/export', [SeniorCitizenController::class, 'export'])->name('export');
    });

    Route::middleware('role:admin')->group(function () {
        Route::delete('/{senior}', [SeniorCitizenController::class, 'destroy'])->name('destroy');
    });
});
