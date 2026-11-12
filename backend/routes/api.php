<?php

use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\MenuCategoryController;
use App\Http\Controllers\Api\MenuItemController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();

        return response()->json([
            'status' => 'ok',
            'database' => 'connected',
            'application' => 'Quan Nhau Tu Do',
        ]);
    } catch (Throwable $exception) {
        return response()->json([
            'status' => 'error',
            'database' => 'disconnected',
        ], 503);
    }
});

Route::get('/branches', [
    BranchController::class,
    'index'
]);

Route::apiResource(
    '/categories',
    MenuCategoryController::class
)->only(['index', 'store', 'update', 'destroy']);

Route::put(
    '/menu-items/{id}/branch-status',
    [MenuItemController::class, 'updateBranchStatus']
);

Route::apiResource(
    '/menu-items',
    MenuItemController::class
)->only(['index', 'store', 'update', 'destroy']);
