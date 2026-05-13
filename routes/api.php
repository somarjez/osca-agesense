<?php

use App\Http\Controllers\GisApiController;
use Illuminate\Support\Facades\Route;

Route::get('/gis/seniors', [GisApiController::class, 'seniors'])->name('api.gis.seniors');
Route::get('/gis/facilities', [GisApiController::class, 'facilities'])->name('api.gis.facilities');
