<?php

use App\Http\Controllers\Api\V1\SaleController;
use App\Http\Controllers\Api\V1\SaleReturnController;
use App\Http\Middleware\PosKey;
use Illuminate\Support\Facades\Route;

// Sales (POS). D-028: the optional shared key guards the write only; D-024: the `pos` limiter caps floods.
Route::post('/sales', [SaleController::class, 'store'])->middleware([PosKey::class, 'throttle:pos']);
Route::get('/sales', [SaleController::class, 'index']);
// D-049: a refund is a new document against a sale; same write protection as POST /sales.
Route::post('/sales/{sale}/returns', [SaleReturnController::class, 'store'])->middleware([PosKey::class, 'throttle:pos']);
