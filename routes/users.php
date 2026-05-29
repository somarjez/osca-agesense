<?php

use App\Http\Controllers\UserManagementController;

Route::prefix('users')->name('users.')->middleware('role:admin')->group(function () {
    Route::get('/',            [UserManagementController::class, 'index'])->name('index');
    Route::get('/create',      [UserManagementController::class, 'create'])->name('create');
    Route::post('/',           [UserManagementController::class, 'store'])->name('store');
    // Editing is handled by an in-place modal on the index (no separate edit page).
    Route::put('/{user}',      [UserManagementController::class, 'update'])->name('update');
    Route::delete('/{user}',   [UserManagementController::class, 'destroy'])->name('destroy');
});
