<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['application' => 'Quan Nhau Tu Do API', 'api' => '/api/health']));
