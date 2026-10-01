<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // D-019: the envelope owns the `data` key, so resources must not add a second one (no data.data).
        JsonResource::withoutWrapping();

        // D-024: a POS integration must not be able to flood the ledger. Applied to POST /sales in PTY-9.
        RateLimiter::for('pos', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
