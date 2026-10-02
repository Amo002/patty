<?php

namespace App\Providers;

use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\RecipeLine;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Support\Audit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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
        // Short names in reference_type / subject_type, so renaming a class never orphans rows.
        // Enforced: an unmapped model throws instead of silently storing a class name.
        Relation::enforceMorphMap([
            'ingredient' => Ingredient::class,
            'supplier' => Supplier::class,
            'menu_item' => MenuItem::class,
            'recipe_line' => RecipeLine::class,
            'purchase_order' => PurchaseOrder::class,
            'purchase_order_line' => PurchaseOrderLine::class,
            'delivery' => Delivery::class,
            'delivery_line' => DeliveryLine::class,
            'sale' => Sale::class,
            'stock_movement' => StockMovement::class,
        ]);

        // D-019: the envelope owns the `data` key, so resources must not add a second one (no data.data).
        JsonResource::withoutWrapping();

        // D-021: every activity row, from Audit::record or the LogsActivity trait, gets channel, request_id and ip.
        LogActivityAction::beforeLogging(fn (Model $activity) => Audit::stamp($activity));

        // D-024: a POS integration must not be able to flood the ledger. Applied to POST /sales in PTY-9.
        RateLimiter::for('pos', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
