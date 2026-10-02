<?php

use App\Http\Controllers\Api\V1\IngredientController;
use App\Http\Controllers\Api\V1\MenuItemController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

// Catalog routes (ingredients, suppliers, menu items).

Route::get('/ingredients', [IngredientController::class, 'index']);
Route::post('/ingredients', [IngredientController::class, 'store']);
Route::get('/ingredients/{ingredient}', [IngredientController::class, 'show']);
Route::patch('/ingredients/{ingredient}', [IngredientController::class, 'update']);

Route::get('/suppliers', [SupplierController::class, 'index']);
Route::post('/suppliers', [SupplierController::class, 'store']);
Route::get('/suppliers/{supplier}', [SupplierController::class, 'show']);
Route::patch('/suppliers/{supplier}', [SupplierController::class, 'update']);

Route::get('/menu-items', [MenuItemController::class, 'index']);
Route::post('/menu-items', [MenuItemController::class, 'store']);
Route::get('/menu-items/{menuItem}', [MenuItemController::class, 'show']);
Route::patch('/menu-items/{menuItem}', [MenuItemController::class, 'update']);
Route::put('/menu-items/{menuItem}/recipe', [MenuItemController::class, 'replaceRecipe']);
