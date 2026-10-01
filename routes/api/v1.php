<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

// D-040: each area owns one file, so parallel tickets never edit the same routes file.
foreach (glob(__DIR__.'/v1/*.php') as $areaFile) {
    require $areaFile;
}
