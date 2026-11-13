<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'application' => 'Quan Nhau Tu Do API', 'api' => '/api/health'
]));

// Các route web này sử dụng session + CSRF mặc định của Laravel.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');