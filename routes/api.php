<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\PropertyController;
use App\Http\Controllers\Api\ReservationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/imports', [ImportController::class, 'store']);
Route::get('/imports/{import}', [ImportController::class, 'show']);
Route::get('/properties', [PropertyController::class, 'index']);
Route::post('/offers/{offer}/reservations', [ReservationController::class, 'store']);