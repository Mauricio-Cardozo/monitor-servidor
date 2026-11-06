<?php

use App\Http\Controllers\DemoController;
use App\Http\Controllers\ServerController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/servidor', [ServerController::class, 'index']);

Route::post('/demo/abrir/{app}', [DemoController::class, 'abrir'])
    ->whereIn('app', ['calculadora', 'bloc'])
    ->middleware('throttle:2,1'); // cada PC puede abrir 2 programas por minuto
