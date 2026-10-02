<?php

use App\Models\Ingredient;
use App\Models\StockMovement;
use App\Support\DemoTools;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| E30 to E32 and their artisan twins (PTY-22, D-037, S13, T24).
|
| The app boots with APP_ENV=testing, so the demo routes are not registered at boot. These tests switch the
| environment and then load routes/api/v1/demo.php the way bootstrap/app.php loads every area file, which
| exercises the real guard in that file rather than a copy of it.
*/

/** Every table a manager's data lands in (activity_log and document_sequences included). */
const DEMO_TABLES = [
    'ingredients', 'suppliers', 'menu_items', 'recipe_lines', 'purchase_orders', 'purchase_order_lines',
    'deliveries', 'delivery_lines', 'sales', 'stock_movements', 'activity_log', 'document_sequences',
];

/*
| migrate:fresh ends with VACUUM, which SQLite refuses inside a transaction, and RefreshDatabase wraps every
| test in one. Leaving it first is safe: each test boots its own in-memory database (see tests/Pest.php).
*/
beforeEach(fn () => DB::rollBack());

function loadDemoRoutesIn(string $environment): void
{
    app()->detectEnvironment(fn () => $environment);

    Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api/v1/demo.php'));
}

it('has no demo routes at all when the app boots outside local (T24)', function () {
    $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

    expect($uris)->not->toContain('api/v1/demo/reset', 'api/v1/demo/clear', 'api/v1/demo/seed');
});

it('answers 404 to E30, E31 and E32 outside local, even when the route file is loaded (T24)', function (string $path) {
    loadDemoRoutesIn('production');

    $this->postJson("/api/v1/demo/{$path}")->assertNotFound()->assertJsonPath('code', 'not_found');
})->with(['reset', 'clear', 'seed']);

it('registers the demo routes in local, so the 404 above is the guard and not a missing file (T24)', function () {
    loadDemoRoutesIn('local');

    $this->postJson('/api/v1/demo/seed')->assertOk();
});

it('seeds an empty system and reports the counts (E32)', function () {
    loadDemoRoutesIn('local');

    $this->postJson('/api/v1/demo/seed')
        ->assertOk()
        ->assertJsonPath('data.counts.ingredients', 12)
        ->assertJsonPath('data.counts.suppliers', 4)
        ->assertJsonPath('data.counts.menu_items', 5)
        ->assertJsonPath('data.counts.purchase_orders', 7)
        ->assertJsonPath('data.counts.sales', DemoSeeder::SALES);
});

it('refuses to seed over existing data with 409 demo_not_empty and changes nothing (E32)', function () {
    loadDemoRoutesIn('local');
    $this->postJson('/api/v1/demo/seed')->assertOk();
    $before = DemoTools::counts();

    $this->postJson('/api/v1/demo/seed')->assertStatus(409)->assertJsonPath('code', 'demo_not_empty');

    expect(DemoTools::counts())->toBe($before);
});

it('refuses to seed when only one thing exists, whichever it is (E32)', function () {
    loadDemoRoutesIn('local');
    Ingredient::factory()->create();

    $this->postJson('/api/v1/demo/seed')->assertStatus(409)->assertJsonPath('code', 'demo_not_empty');
});

it('leaves zero rows in every table when cleared, the append-only ledger included (E31)', function () {
    loadDemoRoutesIn('local');
    $this->postJson('/api/v1/demo/seed')->assertOk();
    expect(StockMovement::query()->count())->toBeGreaterThan(0);

    $this->postJson('/api/v1/demo/clear')->assertOk();

    foreach (DEMO_TABLES as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} should be empty");
    }
    expect(DemoTools::isEmpty())->toBeTrue();
});

it('lets the demo be loaded again after a clear (E31, E32)', function () {
    loadDemoRoutesIn('local');
    $this->postJson('/api/v1/demo/seed')->assertOk();
    $this->postJson('/api/v1/demo/clear')->assertOk();

    $this->postJson('/api/v1/demo/seed')->assertOk()->assertJsonPath('data.counts.sales', DemoSeeder::SALES);
});

it('resets to identical counts every time, over data of any shape (E30)', function () {
    loadDemoRoutesIn('local');

    $first = $this->postJson('/api/v1/demo/reset')->assertOk()->json('data.counts');
    Ingredient::factory()->create(['name' => 'Added by hand']);
    $second = $this->postJson('/api/v1/demo/reset')->assertOk()->json('data.counts');

    expect($second)->toBe($first)
        ->and(Ingredient::query()->where('name', 'Added by hand')->exists())->toBeFalse();
});

it('restarts document numbers after a reset, so the demo always starts at PO 0001 (E30)', function () {
    loadDemoRoutesIn('local');
    $this->postJson('/api/v1/demo/reset')->assertOk();

    expect(DB::table('purchase_orders')->orderBy('id')->value('number'))->toEndWith('-0001');
});
