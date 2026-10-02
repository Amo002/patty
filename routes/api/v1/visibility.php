<?php

use App\Http\Controllers\Api\V1\ActivityController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\MovementController;
use App\Http\Controllers\Api\V1\StockController;
use Illuminate\Support\Facades\Route;

// Read-only views (PTY-10). E6 lives here, not in catalog.php, because it is a stock view of an ingredient.
Route::get('/stock', [StockController::class, 'index']);
Route::get('/dashboard', DashboardController::class);
Route::get('/activity', ActivityController::class);
Route::get('/ingredients/{ingredient}/movements', MovementController::class);
