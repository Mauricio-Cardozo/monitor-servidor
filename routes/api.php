<?php

use App\Http\Controllers\ServerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/metrics', [ServerController::class, 'api'])
    ->middleware('throttle:60,1'); // maximo 60 pedidos por minuto
