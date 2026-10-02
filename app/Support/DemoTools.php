<?php

namespace App\Support;

use App\Exceptions\Domain\DemoNotEmpty;
use App\Models\Delivery;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Clear, seed and reset the demo data (D-037). The one place the endpoints, the artisan
 * commands and the layout's menu flags all read from, so they cannot disagree.
 */
class DemoTools
{
    /**
     * Demo tools exist only in the local environment (S13): they can wipe the database.
     */
    public static function enabled(): bool
    {
        return app()->environment('local');
    }

    /**
     * True when none of the things a manager creates exist yet: ingredients, suppliers,
     * menu items, purchase orders, sales. Stock movements and deliveries cannot exist without them.
     */
    public static function isEmpty(): bool
    {
        try {
            return ! Ingredient::query()->exists()
                && ! Supplier::query()->exists()
                && ! MenuItem::query()->exists()
                && ! PurchaseOrder::query()->exists()
                && ! Sale::query()->exists();
        } catch (Throwable) {
            // Tables missing (migrations not run): the layout must still render, and "Load demo data" must not offer itself.
            return false;
        }
    }

    /**
     * Row counts per table, for the 200 responses and the artisan output.
     *
     * @return array<string, int>
     */
    public static function counts(): array
    {
        return [
            'ingredients' => Ingredient::query()->count(),
            'suppliers' => Supplier::query()->count(),
            'menu_items' => MenuItem::query()->count(),
            'purchase_orders' => PurchaseOrder::query()->count(),
            'deliveries' => Delivery::query()->count(),
            'sales' => Sale::query()->count(),
            'stock_movements' => StockMovement::query()->count(),
        ];
    }

    /**
     * Empty everything by rebuilding the database.
     *
     * migrate:fresh and not DELETE: the append-only triggers on stock_movements refuse DELETE
     * (D-024, D-037), as they should. Erasing history in a demo means starting a new database,
     * which is honest and visible; even our own tooling cannot quietly rewrite the ledger.
     */
    public static function clear(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    /**
     * Load the demo data into an empty system.
     *
     * @return array<string, int> the counts after seeding
     *
     * @throws DemoNotEmpty when anything exists already
     */
    public static function seed(): array
    {
        if (! self::isEmpty()) {
            throw new DemoNotEmpty;
        }

        app(DemoSeeder::class)->setContainer(app())->__invoke();

        return self::counts();
    }

    /**
     * Clear, then seed: the same starting point every time.
     *
     * @return array<string, int>
     */
    public static function reset(): array
    {
        self::clear();

        return self::seed();
    }
}
