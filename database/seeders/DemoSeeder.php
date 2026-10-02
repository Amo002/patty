<?php

namespace Database\Seeders;

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;
use App\Services\IngredientService;
use App\Services\MenuService;
use App\Services\PurchaseOrderService;
use App\Services\ReceivingService;
use App\Services\SaleService;
use App\Services\SupplierService;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * A believable single-branch burger restaurant (D-036), built ONLY through the real
 * services, so every row obeys every rule and carries its movements, audit entries
 * and document numbers. A raw insert would produce data the app itself could never make.
 *
 * Deterministic: mt_srand fixes the sales, and all times are relative to the start of
 * today (not to the clock time), so two runs on the same day give identical counts and stock.
 * Time is moved with Carbon::setTestNow between steps, then always reset.
 */
class DemoSeeder extends Seeder
{
    public const RANDOM_SEED = 20261004;

    public const SALES = 120;

    /** @var array<string, Ingredient> keyed by slug */
    private array $ingredients = [];

    /** @var array<string, Supplier> keyed by slug */
    private array $suppliers = [];

    /** @var array<string, MenuItem> keyed by slug */
    private array $menu = [];

    public function run(
        IngredientService $ingredientService,
        SupplierService $supplierService,
        MenuService $menuService,
        PurchaseOrderService $orders,
        ReceivingService $receiving,
        SaleService $sales,
    ): void {
        mt_srand(self::RANDOM_SEED);
        $today = Carbon::today();

        // Every activity row gets channel `api` (there is no request to take it from) and `seed: true`, so the
        // trail says these rows came from the seeder. Audit::stamp would read the channel from the request header
        // when the seed is triggered from the UI, so the override runs after it.
        LogActivityAction::clearBeforeLoggingCallbacks();
        LogActivityAction::beforeLogging(function (Model $activity) {
            Audit::stamp($activity);
            $activity->properties = $activity->properties->merge(['channel' => 'api', 'seed' => true]);
        });

        try {
            Carbon::setTestNow($today->copy()->subDays(7)->setTime(9, 0));
            $this->catalogue($ingredientService, $supplierService, $menuService);

            $this->purchasing($orders, $receiving, $today);
            $this->sales($sales, $today);
        } finally {
            Carbon::setTestNow();
            // Back to the application's own hook (AppServiceProvider), so nothing after the seed is marked.
            LogActivityAction::clearBeforeLoggingCallbacks();
            LogActivityAction::beforeLogging(fn (Model $activity) => Audit::stamp($activity));
        }
    }

    private function catalogue(IngredientService $ingredientService, SupplierService $supplierService, MenuService $menuService): void
    {
        // slug => [name, unit, extra fields]. Beef alone overrides the under-tolerance (2%), to show the
        // per-ingredient setting (D-035): beef is the costly line, so a 2% shortfall is the most we accept as complete.
        $ingredients = [
            'beef' => ['Beef', Unit::Gram, ['under_tolerance_bps' => 200]],
            'bun' => ['Bun', Unit::Piece, []],
            'cheese' => ['Cheese', Unit::Gram, []],
            'lettuce' => ['Lettuce', Unit::Gram, []],
            'tomato' => ['Tomato', Unit::Gram, []],
            'onion' => ['Onion', Unit::Gram, []],
            'pickles' => ['Pickles', Unit::Gram, []],
            'burger-sauce' => ['Burger sauce', Unit::Millilitre, []],
            'potatoes' => ['Potatoes', Unit::Gram, []],
            'frying-oil' => ['Frying oil', Unit::Millilitre, []],
            'ketchup' => ['Ketchup', Unit::Millilitre, []],
            'chicken-breast' => ['Chicken breast', Unit::Gram, []],
        ];

        foreach ($ingredients as $slug => [$name, $unit, $extra]) {
            // No photos (D-046): image_path stays null and the UI shows the ingredient icon.
            $this->ingredients[$slug] = $ingredientService->create([
                'name' => $name,
                'unit' => $unit->value,
            ] + $extra);
        }

        // Fictional: .example is a reserved domain, and these numbers are not real lines.
        foreach ([
            'al-mashreq-meats' => ['Al-Mashreq Meats', '+962 6 551 2040'],
            'golden-crust-bakery' => ['Golden Crust Bakery', '+962 6 552 3318'],
            'jordan-valley-fresh-produce' => ['Jordan Valley Fresh Produce', '+962 6 553 7725'],
            'dairy-hills' => ['Dairy Hills', '+962 6 554 6091'],
        ] as $slug => [$name, $phone]) {
            $this->suppliers[$slug] = $supplierService->create([
                'name' => $name,
                'email' => "orders@{$slug}.example",
                'phone' => $phone,
            ]);
        }

        // The Classic Burger is exactly the brief's: 150 g beef, 1 bun, 20 g cheese.
        $recipes = [
            'classic-burger' => ['Classic Burger', ['beef' => 150, 'bun' => 1, 'cheese' => 20]],
            'patty-deluxe' => ['Patty Deluxe', ['beef' => 150, 'bun' => 1, 'cheese' => 20, 'lettuce' => 15, 'tomato' => 30, 'onion' => 10, 'pickles' => 10, 'burger-sauce' => 20]],
            'double-patty' => ['Double Patty', ['beef' => 300, 'bun' => 1, 'cheese' => 40, 'burger-sauce' => 20]],
            'crispy-chicken' => ['Crispy Chicken', ['chicken-breast' => 140, 'bun' => 1, 'lettuce' => 15, 'burger-sauce' => 20, 'frying-oil' => 30]],
            'fries' => ['Fries', ['potatoes' => 200, 'frying-oil' => 25, 'ketchup' => 20]],
        ];

        foreach ($recipes as $slug => [$name, $recipe]) {
            $lines = [];
            foreach ($recipe as $ingredientSlug => $quantity) {
                $lines[] = ['ingredient' => $this->ingredients[$ingredientSlug], 'quantity' => $quantity];
            }

            $this->menu[$slug] = $menuService->create($name, $lines);
        }
    }

    /**
     * Purchase orders, one per case. Created in the order that gives the numbers a story the guided tour
     * (PTY-12) relies on: PO-0002 can be partly received more, PO-0003 is the draft to send.
     */
    private function purchasing(PurchaseOrderService $orders, ReceivingService $receiving, Carbon $today): void
    {
        $at = fn (int $daysAgo, int $hour, int $minute = 0) => Carbon::setTestNow($today->copy()->subDays($daysAgo)->setTime($hour, $minute));

        // Created a minute apart, in number order: PO-0001 to PO-0007.
        $at(7, 10, 0);
        $po1 = $this->order($orders, 'al-mashreq-meats', ['beef' => 24000, 'chicken-breast' => 6000]);   // closed, two deliveries
        $at(7, 10, 1);
        $po2 = $this->order($orders, 'dairy-hills', ['cheese' => 5000, 'burger-sauce' => 3000]);        // partially received
        $at(7, 10, 2);
        $this->order($orders, 'golden-crust-bakery', ['bun' => 500]);                                    // draft
        $at(7, 10, 3);
        $po4 = $this->order($orders, 'jordan-valley-fresh-produce', ['lettuce' => 4000, 'tomato' => 6000, 'onion' => 3000, 'pickles' => 1500]); // closed within under-tolerance
        $at(7, 10, 4);
        $po5 = $this->order($orders, 'golden-crust-bakery', ['bun' => 300]);                             // closed with over-receipt
        $at(7, 10, 5);
        $po6 = $this->order($orders, 'jordan-valley-fresh-produce', ['potatoes' => 20000, 'frying-oil' => 4000, 'ketchup' => 2000]); // short-closed
        $at(7, 10, 6);
        $po7 = $this->order($orders, 'al-mashreq-meats', ['beef' => 10000]);                             // sent, nothing arrived

        // Everything but the draft is sent the same afternoon.
        $at(7, 14, 0);
        foreach ([$po1, $po2, $po4, $po5, $po6, $po7] as $po) {
            $orders->send($po);
        }

        // (a) PO-0001: the first delivery brings the chicken and part of the beef, the second completes the beef.
        $at(6, 8, 30);
        $this->deliver($receiving, $po1, ['beef' => 14000, 'chicken-breast' => 6000]);
        $at(5, 10, 0);
        $this->deliver($receiving, $po1, ['beef' => 10000]);

        // (b) PO-0004: every line arrives at 98.5% of the order. That is inside the 5% default under-tolerance
        // (none of these is beef), so the order closes normally and short_closed stays false (D-013, D-035).
        $at(6, 9, 0);
        $this->deliver($receiving, $po4, ['lettuce' => 3940, 'tomato' => 5910, 'onion' => 2955, 'pickles' => 1478]);

        // (c) PO-0005: 315 buns for 300 ordered. Exactly +5%, the most the default allows.
        $at(6, 9, 30);
        $this->deliver($receiving, $po5, ['bun' => 315]);

        // (e) PO-0002: cheese is only partly there and the sauce is short, so the order stays received. The cheese
        // is deliberately too little for three days of sales: stock goes negative, which D-010 shows rather than hides.
        $at(4, 10, 0);
        $this->deliver($receiving, $po2, ['cheese' => 2000, 'burger-sauce' => 1800]);

        // (d) PO-0006: potatoes arrive at 75%, far below the tolerance, and the supplier will not send the rest.
        $at(4, 11, 0);
        $this->deliver($receiving, $po6, ['potatoes' => 15000, 'frying-oil' => 4000, 'ketchup' => 2000]);
        $at(4, 15, 0);
        $orders->shortClose($po6);
    }

    /**
     * Sales over the last three days with lunch and dinner peaks and one replay, recorded in time
     * order so movement ids follow the clock.
     */
    private function sales(SaleService $sales, Carbon $today): void
    {
        // Weighted picks: mostly Classic and Fries.
        $items = array_merge(
            array_fill(0, 45, 'classic-burger'),
            array_fill(0, 30, 'fries'),
            array_fill(0, 10, 'patty-deluxe'),
            array_fill(0, 8, 'double-patty'),
            array_fill(0, 7, 'crispy-chicken'),
        );
        $quantities = array_merge(array_fill(0, 55, 1), array_fill(0, 30, 2), array_fill(0, 10, 3), array_fill(0, 5, 4));

        $planned = [];
        $now = Carbon::now();

        for ($i = 0; $i < self::SALES; $i++) {
            $daysAgo = mt_rand(0, 2);
            // 60% lunch (12:00 to 14:59), 40% dinner (19:00 to 22:59).
            $hour = mt_rand(1, 100) <= 60 ? mt_rand(12, 14) : mt_rand(19, 22);
            $time = $today->copy()->subDays($daysAgo)->setTime($hour, mt_rand(0, 59), mt_rand(0, 59));

            // A sale cannot be in the future: a peak that has not happened yet today moves to the day before,
            // so there are always exactly SALES sales whatever the time of day the seed runs.
            if ($time->greaterThan($now)) {
                $time->subDay();
            }

            $planned[] = [$time, $items[mt_rand(0, count($items) - 1)], $quantities[mt_rand(0, count($quantities) - 1)]];
        }

        usort($planned, fn (array $a, array $b) => $a[0] <=> $b[0]);

        foreach ($planned as $index => [$time, $slug, $quantity]) {
            Carbon::setTestNow($time);
            $reference = sprintf('seed-till-1:%05d', $index + 1);
            $sales->record($this->menu[$slug], $quantity, $reference, $time);

            // The till's link hiccups once and it sends the same sale again (D-029): the replay moves no stock.
            if ($index === 59) {
                Carbon::setTestNow($time->copy()->addSeconds(20));
                $sales->record($this->menu[$slug], $quantity, $reference, $time);
            }
        }
    }

    /**
     * @param  array<string, int>  $lines  ingredient slug => quantity ordered
     */
    private function order(PurchaseOrderService $orders, string $supplier, array $lines): PurchaseOrder
    {
        $resolved = [];
        foreach ($lines as $slug => $quantity) {
            $resolved[] = ['ingredient' => $this->ingredients[$slug], 'quantity_ordered' => $quantity];
        }

        return $orders->create($this->suppliers[$supplier], $resolved);
    }

    /**
     * @param  array<string, int>  $quantities  ingredient slug => quantity received
     */
    private function deliver(ReceivingService $receiving, PurchaseOrder $po, array $quantities): void
    {
        $poLines = PurchaseOrderLine::query()->where('purchase_order_id', $po->id)->get()->keyBy('ingredient_id');

        $lines = [];
        foreach ($quantities as $slug => $quantity) {
            $lines[] = ['purchase_order_line' => $poLines[$this->ingredients[$slug]->id], 'quantity' => $quantity];
        }

        $receiving->receive($po, $lines, Carbon::now());
    }
}
