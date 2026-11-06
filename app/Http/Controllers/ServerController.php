<?php

namespace App\Http\Controllers;

use App\Services\ServerMetrics;

class ServerController extends Controller
{
    // Laravel crea el ServerMetrics y lo pasa solo (inyeccion de dependencias)
    public function index(ServerMetrics $metrics)
    {
        return view('servidor', ['data' => $metrics->snapshot()]);
    }

    public function api(ServerMetrics $metrics)
    {
        return response()->json($metrics->snapshot());
    }
}
