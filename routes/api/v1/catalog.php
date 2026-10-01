<?php

use App\Http\Controllers\Api\V1\MenuItemController;
use Illuminate\Support\Facades\Route;

// Catalog routes (ingredients, suppliers, menu items). Ingredients and suppliers are added by PTY-5.

Route::get('/menu-items', [MenuItemController::class, 'index']);
Route::post('/menu-items', [MenuItemController::class, 'store']);
Route::get('/menu-items/{menuItem}', [MenuItemController::class, 'show']);
Route::patch('/menu-items/{menuItem}', [MenuItemController::class, 'update']);
Route::put('/menu-items/{menuItem}/recipe', [MenuItemController::class, 'replaceRecipe']);
