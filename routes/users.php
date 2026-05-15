<?php

use App\Http\Controllers\UserManagementController;

Route::prefix('users')->name('users.')->middleware('role:admin')->group(function () {
    Route::get('/',            [UserManagementController::class, 'index'])->name('index');
    Route::get('/create',      [UserManagementController::class, 'create'])->name('create');
    Route::post('/',           [UserManagementController::class, 'store'])->name('store');
    Route::get('/{user}/edit', [UserManagementController::class, 'edit'])->name('edit');
    Route::put('/{user}',      [UserManagementController::class, 'update'])->name('update');
    Route::delete('/{user}',   [UserManagementController::class, 'destroy'])->name('destroy');
});
