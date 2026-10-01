<?php

namespace App\Providers;

use App\Support\Audit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Actions\LogActivityAction;

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

        // D-021: every activity row, from Audit::record or the LogsActivity trait, gets channel, request_id and ip.
        LogActivityAction::beforeLogging(fn (Model $activity) => Audit::stamp($activity));

        // D-024: a POS integration must not be able to flood the ledger. Applied to POST /sales in PTY-9.
        RateLimiter::for('pos', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
