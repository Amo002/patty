<?php

use App\Http\Controllers\Api\V1\PurchaseOrderController;
use Illuminate\Support\Facades\Route;

// Purchasing routes. Purchase orders are PTY-7; deliveries are added by PTY-8.
Route::get('/purchase-orders', [PurchaseOrderController::class, 'index']);
Route::post('/purchase-orders', [PurchaseOrderController::class, 'store']);
Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show']);
Route::put('/purchase-orders/{purchaseOrder}/lines', [PurchaseOrderController::class, 'replaceLines']);
Route::post('/purchase-orders/{purchaseOrder}/send', [PurchaseOrderController::class, 'send']);
Route::post('/purchase-orders/{purchaseOrder}/close', [PurchaseOrderController::class, 'close']);
Route::delete('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy']);
