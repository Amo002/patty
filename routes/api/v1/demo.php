<?php

use App\Http\Controllers\Api\V1\DemoController;
use Illuminate\Support\Facades\Route;

// D-037, S13: these can wipe the database, so they exist only when APP_ENV=local. Anywhere else the
// routes are never registered and every one of them is a plain 404, not a 403 that admits they exist.
if (app()->environment('local')) {
    Route::post('/demo/reset', [DemoController::class, 'reset']);
    Route::post('/demo/clear', [DemoController::class, 'clear']);
    Route::post('/demo/seed', [DemoController::class, 'seed']);
}
