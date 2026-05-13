<?php

use App\Http\Controllers\GisApiController;
use Illuminate\Support\Facades\Route;

Route::get('/gis/seniors', [GisApiController::class, 'seniors'])->name('api.gis.seniors');
Route::get('/gis/facilities', [GisApiController::class, 'facilities'])->name('api.gis.facilities');
Route::get('/gis/boundary/pagsanjan', [GisApiController::class, 'pagsanjanBoundary'])->name('api.gis.boundary.pagsanjan');
Route::get('/gis/boundary/barangays', [GisApiController::class, 'barangayBoundaries'])->name('api.gis.boundary.barangays');
