<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\PropertyController;
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/imports', [ImportController::class, 'store']);
Route::get('/imports/{import}', [ImportController::class, 'show']);
Route::get('/properties', [PropertyController::class, 'index']);
