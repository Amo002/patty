<?php

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\PurchaseOrderLine;
use App\Models\RecipeLine;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Spatie\Activitylog\Models\Activity;

/*
| E2 to E5 (PTY-5). Every JSON response is checked with assertNoIntegerIds (D-031).
*/

function addMovement(Ingredient $ingredient, int $delta): void
{
    StockMovement::factory()->create(['ingredient_id' => $ingredient->id, 'quantity_delta' => $delta]);
}

it('creates an ingredient with a ULID id, zero stock and the default tolerance', function () {
    $response = $this->postJson('/api/v1/ingredients', ['name' => 'Lettuce', 'unit' => 'g'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Lettuce')
        ->assertJsonPath('data.unit', 'g')
        ->assertJsonPath('data.unit_label', 'Grams')
        ->assertJsonPath('data.on_hand', 0)
        ->assertJsonPath('data.is_negative', false)
        ->assertJsonPath('data.unit_locked', false)
        ->assertJsonPath('data.tolerance.source', 'default')
        ->assertJsonPath('data.image_url', null);
    expect($response->json())->assertNoIntegerIds();

    expect($response->json('data.id'))->toBe(Ingredient::firstOrFail()->ulid);
    expect($response->json('data'))->not->toHaveKey('incoming');
});

it('lists ingredients by name with on_hand from the movements', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);
    $bun = Ingredient::factory()->create(['name' => 'Bun', 'unit' => Unit::Piece]);
    $cheese = Ingredient::factory()->create(['name' => 'Cheese', 'unit' => Unit::Gram]);

    $before = $this->getJson('/api/v1/ingredients')->assertOk();
    expect($before->json())->assertNoIntegerIds();
    expect(collect($before->json('data'))->pluck('on_hand')->all())->toBe([0, 0, 0]);

    addMovement($beef, 100);
    addMovement($beef, -30);
    addMovement($bun, -2);

    $response = $this->getJson('/api/v1/ingredients')->assertOk();
    expect($response->json())->assertNoIntegerIds();

    $rows = collect($response->json('data'));
    expect($rows->pluck('name')->all())->toBe(['Beef', 'Bun', 'Cheese']);
    expect($rows->pluck('on_hand')->all())->toBe([70, -2, 0]);
    // Negative stock is shown, not hidden (D-007).
    expect($rows->pluck('is_negative')->all())->toBe([false, true, false]);
    expect($rows->pluck('unit_locked')->all())->toBe([true, true, false]);
});

it('shows one ingredient with its current stock', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef']);
    addMovement($beef, 100);

    $response = $this->getJson("/api/v1/ingredients/{$beef->ulid}")->assertOk()->assertJsonPath('data.on_hand', 100);
    expect($response->json())->assertNoIntegerIds();
});

it('returns 404 for a numeric id', function () {
    Ingredient::factory()->create(['name' => 'Beef']);

    $response = $this->getJson('/api/v1/ingredients/1')->assertNotFound();
    expect($response->json())->assertNoIntegerIds();
    expect($this->patchJson('/api/v1/ingredients/1', ['name' => 'Mince'])->assertNotFound()->json())->assertNoIntegerIds();
});

it('rejects a duplicate name regardless of case or padding', function () {
    Ingredient::factory()->create(['name' => 'Beef']);

    foreach (['beef', ' Beef ', 'BEEF'] as $name) {
        $response = $this->postJson('/api/v1/ingredients', ['name' => $name, 'unit' => 'g'])->assertStatus(422);
        expect($response->json())->assertNoIntegerIds();
        expect($response->json('errors'))->toHaveKey('name');
    }

    expect(Ingredient::count())->toBe(1);
});

it('rejects renaming onto another ingredient but allows keeping or re-casing its own name', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef']);
    Ingredient::factory()->create(['name' => 'Bun']);

    $clash = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['name' => 'bun'])->assertStatus(422);
    expect($clash->json())->assertNoIntegerIds();
    expect($clash->json('errors'))->toHaveKey('name');

    expect($this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['name' => 'Beef'])->assertOk()->json())->assertNoIntegerIds();
    expect($this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['name' => 'BEEF'])->assertOk()->json())->assertNoIntegerIds();
});

it('rejects an invalid or missing unit', function () {
    foreach ([['name' => 'Salt', 'unit' => 'kg'], ['name' => 'Salt']] as $payload) {
        $response = $this->postJson('/api/v1/ingredients', $payload)->assertStatus(422);
        expect($response->json())->assertNoIntegerIds();
        expect($response->json('errors.unit.0'))->toBe('Unit must be one of g, ml, piece.');
    }

    expect(Ingredient::count())->toBe(0);
});

it('accepts names at the length boundaries and rejects outside them', function () {
    expect($this->postJson('/api/v1/ingredients', ['name' => 'ab', 'unit' => 'g'])->assertCreated()->json())->assertNoIntegerIds();
    expect($this->postJson('/api/v1/ingredients', ['name' => str_repeat('c', 100), 'unit' => 'g'])->assertCreated()->json())->assertNoIntegerIds();

    foreach (['a', str_repeat('d', 101)] as $name) {
        $response = $this->postJson('/api/v1/ingredients', ['name' => $name, 'unit' => 'g'])->assertStatus(422);
        expect($response->json())->assertNoIntegerIds();
        expect($response->json('errors'))->toHaveKey('name');
    }
});

it('rejects an array where a string is expected', function () {
    $response = $this->postJson('/api/v1/ingredients', ['name' => ['Beef'], 'unit' => ['g']])->assertStatus(422);

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('errors'))->toHaveKeys(['name', 'unit']);
});

it('allows a unit change before any movement and locks it after', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);

    $open = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['unit' => 'ml'])
        ->assertOk()
        ->assertJsonPath('data.unit', 'ml')
        ->assertJsonPath('data.unit_label', 'Millilitres');
    expect($open->json())->assertNoIntegerIds();

    expect($this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['unit' => 'g'])->assertOk()->json())->assertNoIntegerIds();
    addMovement($beef, 100);

    $locked = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['unit' => 'ml'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'unit_locked')
        ->assertJsonPath('message', "Beef is already used in stock history, so its unit (g) can't change.");
    expect($locked->json())->assertNoIntegerIds();
    expect($beef->fresh()->unit)->toBe(Unit::Gram);
});

it('still lets the manager rename or resend the same unit once stock has moved', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);
    addMovement($beef, 100);

    $response = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['name' => 'Minced Beef', 'unit' => 'g'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Minced Beef')
        ->assertJsonPath('data.unit_locked', true);
    expect($response->json())->assertNoIntegerIds();
});

it('keeps a locked unit locked after the stock nets to zero', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);
    addMovement($beef, 100);
    addMovement($beef, -100);

    // The lock follows history, not the balance.
    $locked = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['unit' => 'ml'])->assertStatus(409)->assertJsonPath('code', 'unit_locked');
    expect($locked->json())->assertNoIntegerIds();
    $show = $this->getJson("/api/v1/ingredients/{$beef->ulid}");
    expect($show->json())->assertNoIntegerIds();
    expect($show->json('data.unit_locked'))->toBeTrue();
});

it('stores a tolerance override, reads it back as source ingredient, and resets it with null', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);
    $defaults = $this->getJson("/api/v1/ingredients/{$beef->ulid}")->json('data.tolerance');
    expect($defaults['source'])->toBe('default');

    $set = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['over_tolerance_bps' => 1000, 'over_tolerance_cap' => 0])->assertOk();
    expect($set->json())->assertNoIntegerIds();
    expect($set->json('data.tolerance'))->toBe([
        'over_bps' => 1000,
        // Not sent, so it keeps falling back to the unit default.
        'under_bps' => $defaults['under_bps'],
        // 0 is a real override ("no allowance"), not "unset".
        'over_cap' => 0,
        'source' => 'ingredient',
    ]);
    expect($this->getJson("/api/v1/ingredients/{$beef->ulid}")->json('data.tolerance.source'))->toBe('ingredient');

    $reset = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['over_tolerance_bps' => null, 'over_tolerance_cap' => null])->assertOk();
    expect($reset->json())->assertNoIntegerIds();
    expect($reset->json('data.tolerance'))->toBe($defaults);
});

it('accepts tolerance values at their bounds and rejects values outside them', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef']);

    $bounds = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", [
        'over_tolerance_bps' => 10000, 'under_tolerance_bps' => 0, 'over_tolerance_cap' => 1000000,
    ])->assertOk();
    expect($bounds->json())->assertNoIntegerIds();

    $bad = [
        ['over_tolerance_bps' => 10001],
        ['under_tolerance_bps' => -1],
        ['over_tolerance_cap' => 1000001],
        ['over_tolerance_bps' => true],
        ['over_tolerance_cap' => '5'],
        ['under_tolerance_bps' => 5.5],
    ];

    foreach ($bad as $payload) {
        $response = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", $payload)->assertStatus(422);
        expect($response->json())->assertNoIntegerIds();
        expect($response->json('errors'))->toHaveKey(array_key_first($payload));
    }

    // The rejected requests changed nothing.
    expect($beef->fresh()->over_tolerance_bps)->toBe(10000);
});

it('exposes image_url as null without an image and absolute with one', function () {
    $plain = Ingredient::factory()->create(['name' => 'Salt']);
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'image_path' => 'images/seed/ingredients/beef.webp']);

    expect($this->getJson("/api/v1/ingredients/{$plain->ulid}")->json('data.image_url'))->toBeNull();

    $response = $this->getJson("/api/v1/ingredients/{$beef->ulid}")->assertOk();
    expect($response->json())->assertNoIntegerIds();
    expect($response->json('data.image_url'))->toBe(asset('images/seed/ingredients/beef.webp'))->toStartWith('http');
});

it('writes an activity row with the old and new name on a rename', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef']);
    Activity::query()->delete();

    expect($this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['name' => 'Minced Beef'])->assertOk()->json())->assertNoIntegerIds();

    $entry = Activity::where('event', 'updated')->sole();
    expect($entry->subject_id)->toBe($beef->id);
    expect($entry->attribute_changes->toArray())->toBe(['attributes' => ['name' => 'Minced Beef'], 'old' => ['name' => 'Beef']]);
});

it('writes no activity row and no log line for a no-op update', function () {
    config(['logging.channels.catalog' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);
    Activity::query()->delete();
    $messages = fn () => array_map(fn ($r) => $r->message, Log::channel('catalog')->getLogger()->getHandlers()[0]->getRecords());

    expect($this->patchJson("/api/v1/ingredients/{$beef->ulid}", [])->assertOk()->json())->assertNoIntegerIds();
    $same = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['name' => 'Beef', 'unit' => 'g', 'over_tolerance_bps' => null])->assertOk();
    expect($same->json())->assertNoIntegerIds();

    expect(Activity::count())->toBe(0);
    expect($messages())->toBe([]);

    expect($this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['name' => 'Mince'])->assertOk()->json())->assertNoIntegerIds();
    expect($messages())->toBe(['Ingredient updated']);
});

it('logs one catalog line on create', function () {
    config(['logging.channels.catalog' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);

    $response = $this->postJson('/api/v1/ingredients', ['name' => 'Lettuce', 'unit' => 'g'])->assertCreated();
    expect($response->json())->assertNoIntegerIds();

    $records = Log::channel('catalog')->getLogger()->getHandlers()[0]->getRecords();
    expect(array_map(fn ($r) => $r->message, $records))->toBe(['Ingredient created']);
});

it('writes one created activity row on create', function () {
    $response = $this->postJson('/api/v1/ingredients', ['name' => 'Lettuce', 'unit' => 'g'])->assertCreated();
    expect($response->json())->assertNoIntegerIds();

    $entry = Activity::where('event', 'created')->sole();
    expect($entry->subject_id)->toBe(Ingredient::firstOrFail()->id);
    expect($entry->attribute_changes->toArray()['attributes'])->toMatchArray(['name' => 'Lettuce', 'unit' => 'g']);
});

it('locks the unit when only a recipe line uses the ingredient', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);
    RecipeLine::factory()->create(['ingredient_id' => $beef->id, 'quantity' => 150]);
    expect(StockMovement::count())->toBe(0);

    $locked = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['unit' => 'ml'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'unit_locked')
        ->assertJsonPath('message', "Beef is already used in recipes, so its unit (g) can't change.");
    expect($locked->json())->assertNoIntegerIds();
    expect($beef->fresh()->unit)->toBe(Unit::Gram);

    $show = $this->getJson("/api/v1/ingredients/{$beef->ulid}")->assertOk();
    expect($show->json())->assertNoIntegerIds();
    expect($show->json('data.unit_locked'))->toBeTrue();
});

it('locks the unit when only a purchase order line uses the ingredient', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);
    PurchaseOrderLine::factory()->create(['ingredient_id' => $beef->id, 'quantity_ordered' => 1000]);
    expect(StockMovement::count())->toBe(0);

    $locked = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['unit' => 'ml'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'unit_locked')
        ->assertJsonPath('message', "Beef is already used in purchase orders, so its unit (g) can't change.");
    expect($locked->json())->assertNoIntegerIds();

    $list = $this->getJson('/api/v1/ingredients')->assertOk();
    expect($list->json())->assertNoIntegerIds();
    expect($list->json('data.0.unit_locked'))->toBeTrue();
});

it('keeps the unit free for an unused ingredient while another one is locked', function () {
    $used = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);
    $free = Ingredient::factory()->create(['name' => 'Bun', 'unit' => Unit::Piece]);
    RecipeLine::factory()->create(['ingredient_id' => $used->id, 'quantity' => 150]);

    $list = $this->getJson('/api/v1/ingredients')->assertOk();
    expect($list->json())->assertNoIntegerIds();
    expect(collect($list->json('data'))->pluck('unit_locked', 'name')->all())->toBe(['Beef' => true, 'Bun' => false]);

    $changed = $this->patchJson("/api/v1/ingredients/{$free->ulid}", ['unit' => 'g'])->assertOk()->assertJsonPath('data.unit', 'g');
    expect($changed->json())->assertNoIntegerIds();
});

it('allows resending the same unit for an ingredient used in a recipe', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]);
    RecipeLine::factory()->create(['ingredient_id' => $beef->id, 'quantity' => 150]);

    $response = $this->patchJson("/api/v1/ingredients/{$beef->ulid}", ['unit' => 'g'])->assertOk();
    expect($response->json())->assertNoIntegerIds();
});

it('paginates: 30 ingredients at per_page 25 leave 5 on page 2', function () {
    foreach (range(1, 30) as $n) {
        Ingredient::factory()->create(['name' => sprintf('Item %02d', $n)]);
    }

    $one = $this->getJson('/api/v1/ingredients?per_page=25')->assertOk();
    expect($one->json())->assertNoIntegerIds();
    expect($one->json('data'))->toHaveCount(25);
    expect($one->json('meta.pagination'))->toMatchArray(['page' => 1, 'per_page' => 25, 'total' => 30, 'last_page' => 2, 'has_more' => true]);

    $two = $this->getJson('/api/v1/ingredients?per_page=25&page=2')->assertOk();
    expect($two->json('data'))->toHaveCount(5);
    expect($two->json('meta.pagination.has_more'))->toBeFalse();
    expect($two->json('data.0.name'))->toBe('Item 26');

    expect($this->getJson('/api/v1/ingredients?per_page=101')->assertStatus(422)->json())->assertNoIntegerIds();
});

it('lists 11 ingredients in the same number of queries as 1', function () {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        expect($this->getJson('/api/v1/ingredients')->assertOk()->json())->assertNoIntegerIds();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $first = Ingredient::factory()->create(['name' => 'Item 00']);
    addMovement($first, 10);
    $one = $countQueries();

    foreach (range(1, 10) as $n) {
        $ingredient = Ingredient::factory()->create(['name' => sprintf('Item %02d', $n)]);
        addMovement($ingredient, 10);
        // Lines too, so the recipe and PO lookups are exercised at 11 rows.
        RecipeLine::factory()->create(['ingredient_id' => $ingredient->id, 'quantity' => 5]);
        PurchaseOrderLine::factory()->create(['ingredient_id' => $ingredient->id, 'quantity_ordered' => 5]);
    }

    expect($countQueries())->toBe($one);
});
