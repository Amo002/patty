<?php

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\RecipeLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Spatie\Activitylog\Models\Activity;

/*
| E11 to E15 (PTY-6). Every JSON response is checked with assertNoIntegerIds (D-031).
*/

function menuIngredients(): array
{
    return [
        'beef' => Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]),
        'bun' => Ingredient::factory()->create(['name' => 'Bun', 'unit' => Unit::Piece]),
        'cheese' => Ingredient::factory()->create(['name' => 'Cheese', 'unit' => Unit::Gram]),
    ];
}

function classicBurgerPayload(array $i): array
{
    return [
        'name' => 'Classic Burger',
        'recipe' => [
            ['ingredient_id' => $i['beef']->ulid, 'quantity' => 150],
            ['ingredient_id' => $i['bun']->ulid, 'quantity' => 1],
            ['ingredient_id' => $i['cheese']->ulid, 'quantity' => 20],
        ],
    ];
}

function classicBurger(array $i): MenuItem
{
    $item = MenuItem::factory()->create(['name' => 'Classic Burger']);
    RecipeLine::factory()->create(['menu_item_id' => $item->id, 'ingredient_id' => $i['beef']->id, 'quantity' => 150]);
    RecipeLine::factory()->create(['menu_item_id' => $item->id, 'ingredient_id' => $i['bun']->id, 'quantity' => 1]);
    RecipeLine::factory()->create(['menu_item_id' => $item->id, 'ingredient_id' => $i['cheese']->id, 'quantity' => 20]);

    return $item;
}

it('creates Classic Burger with three lines and returns them with their units', function () {
    $i = menuIngredients();

    $response = $this->postJson('/api/v1/menu-items', classicBurgerPayload($i))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Classic Burger')
        ->assertJsonPath('data.is_sellable', true)
        ->assertJsonPath('data.image_url', null);
    expect($response->json())->assertNoIntegerIds();

    $ulid = $response->json('data.id');
    expect($ulid)->toBe(MenuItem::firstOrFail()->ulid);

    $show = $this->getJson("/api/v1/menu-items/{$ulid}")->assertOk();
    expect($show->json())->assertNoIntegerIds();
    expect($show->json('data.recipe'))->toBe([
        ['ingredient' => ['id' => $i['beef']->ulid, 'name' => 'Beef', 'unit' => 'g'], 'quantity' => 150],
        ['ingredient' => ['id' => $i['bun']->ulid, 'name' => 'Bun', 'unit' => 'piece'], 'quantity' => 1],
        ['ingredient' => ['id' => $i['cheese']->ulid, 'name' => 'Cheese', 'unit' => 'g'], 'quantity' => 20],
    ]);
});

it('exposes image_url as an absolute url when an image path is stored', function () {
    $item = MenuItem::factory()->create(['name' => 'Classic Burger', 'image_path' => 'images/seed/menu/classic-burger.webp']);

    $response = $this->getJson("/api/v1/menu-items/{$item->ulid}")->assertOk();

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('data.image_url'))->toBe(asset('images/seed/menu/classic-burger.webp'))
        ->toStartWith('http');
});

it('creates an item without a recipe as not sellable', function () {
    $response = $this->postJson('/api/v1/menu-items', ['name' => 'Plain Bun'])
        ->assertCreated()
        ->assertJsonPath('data.is_sellable', false)
        ->assertJsonPath('data.recipe', []);
    expect($response->json())->assertNoIntegerIds();

    expect(RecipeLine::count())->toBe(0);
    // No recipe was written, so there is no recipe.replaced entry either.
    expect(Activity::where('event', 'recipe.replaced')->count())->toBe(0);
});

it('rejects a duplicate ingredient in a recipe and names the line', function () {
    $i = menuIngredients();

    $response = $this->postJson('/api/v1/menu-items', [
        'name' => 'Double Beef',
        'recipe' => [
            ['ingredient_id' => $i['beef']->ulid, 'quantity' => 150],
            ['ingredient_id' => $i['bun']->ulid, 'quantity' => 1],
            ['ingredient_id' => $i['beef']->ulid, 'quantity' => 50],
        ],
    ])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('errors')['recipe.2.ingredient_id'][0])->toBe('Line 3 (Beef): ingredient is listed more than once.');
    expect(MenuItem::count())->toBe(0);
});

it('rejects a duplicate on replace even when the ULID differs only by case', function () {
    $i = menuIngredients();
    $item = classicBurger($i);

    $response = $this->putJson("/api/v1/menu-items/{$item->ulid}/recipe", ['lines' => [
        ['ingredient_id' => $i['beef']->ulid, 'quantity' => 150],
        ['ingredient_id' => strtoupper($i['beef']->ulid), 'quantity' => 50],
    ]])->assertStatus(422);

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('errors'))->toHaveKey('lines.1.ingredient_id');
    // The failed replace must leave the old recipe alone.
    expect($item->recipeLines()->count())->toBe(3);
});

it('rejects quantities that are zero, decimal or not numbers, naming line and ingredient', function (mixed $quantity) {
    $i = menuIngredients();

    $response = $this->postJson('/api/v1/menu-items', [
        'name' => 'Test Burger',
        'recipe' => [
            ['ingredient_id' => $i['bun']->ulid, 'quantity' => 1],
            ['ingredient_id' => $i['beef']->ulid, 'quantity' => $quantity],
        ],
    ])->assertStatus(422);

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('errors'))->toHaveKey('recipe.1.quantity')
        ->and($response->json('errors')['recipe.1.quantity'][0])->toStartWith('Line 2 (Beef): quantity ');
    expect(MenuItem::count())->toBe(0);
})->with([true, false, '1.5', 1.5, 'abc', '1e3', -1, 0, null, 'empty array' => [[]]]);

it('says "at least 1" for a zero quantity', function () {
    $i = menuIngredients();

    $this->postJson('/api/v1/menu-items', [
        'name' => 'Test Burger',
        'recipe' => [['ingredient_id' => $i['beef']->ulid, 'quantity' => 0]],
    ])->assertStatus(422)
        ->assertJsonPath('errors', fn ($e) => $e['recipe.0.quantity'][0] === 'Line 1 (Beef): quantity must be at least 1.');
});

it('rejects an unknown or numeric ingredient_id', function (mixed $ingredientId) {
    $response = $this->postJson('/api/v1/menu-items', [
        'name' => 'Test Burger',
        'recipe' => [['ingredient_id' => $ingredientId, 'quantity' => 10]],
    ])->assertStatus(422);

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('errors'))->toHaveKey('recipe.0.ingredient_id');
})->with([
    'unknown ulid' => '01ja8zk3w5y7q2m4n6p8r0t2v4',
    'numeric id' => 1,
    'numeric string' => '1',
]);

it('accepts an uppercase ULID and stores the line against the right ingredient', function () {
    $i = menuIngredients();

    $response = $this->postJson('/api/v1/menu-items', [
        'name' => 'Beef Only',
        'recipe' => [['ingredient_id' => strtoupper($i['beef']->ulid), 'quantity' => 200]],
    ])->assertCreated();

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('data.recipe.0.ingredient.id'))->toBe($i['beef']->ulid);
    expect(RecipeLine::firstOrFail()->ingredient_id)->toBe($i['beef']->id);
});

it('rejects a duplicate name in a different case on create and on rename', function () {
    MenuItem::factory()->create(['name' => 'Classic Burger']);
    $other = MenuItem::factory()->create(['name' => 'Veggie Burger']);

    $create = $this->postJson('/api/v1/menu-items', ['name' => 'CLASSIC burger'])->assertStatus(422);
    expect($create->json())->assertNoIntegerIds();
    expect($create->json('errors')['name'][0])->toBe('A menu item with this name already exists.');

    $rename = $this->patchJson("/api/v1/menu-items/{$other->ulid}", ['name' => 'classic burger'])->assertStatus(422);
    expect($rename->json())->assertNoIntegerIds();
    expect($rename->json('errors'))->toHaveKey('name');
    expect($other->fresh()->name)->toBe('Veggie Burger');
});

it('answers 422, not 500, when the name is an array', function () {
    $item = MenuItem::factory()->create(['name' => 'Classic Burger']);

    $create = $this->postJson('/api/v1/menu-items', ['name' => ['x']])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect($create->json())->assertNoIntegerIds();
    expect($create->json('errors'))->toHaveKey('name');

    $rename = $this->patchJson("/api/v1/menu-items/{$item->ulid}", ['name' => ['x']])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect($rename->json())->assertNoIntegerIds();
    expect($rename->json('errors'))->toHaveKey('name');
});

it('renames an item, allows re-sending its own name, and ignores a PATCH without a name', function () {
    $item = MenuItem::factory()->create(['name' => 'Classic Burger']);

    $renamed = $this->patchJson("/api/v1/menu-items/{$item->ulid}", ['name' => 'Classic Burger XL'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Classic Burger XL');
    expect($renamed->json())->assertNoIntegerIds();

    // The unique rule ignores the item itself, so changing only the case is allowed.
    $this->patchJson("/api/v1/menu-items/{$item->ulid}", ['name' => 'CLASSIC BURGER XL'])->assertOk();
    expect($item->fresh()->name)->toBe('CLASSIC BURGER XL');

    $this->patchJson("/api/v1/menu-items/{$item->ulid}", [])->assertOk()->assertJsonPath('data.name', 'CLASSIC BURGER XL');

    // The rename went through LogsActivity with the dirty field only.
    $entry = Activity::where('event', 'updated')->latest('id')->firstOrFail();
    expect($entry->attribute_changes->toArray())->toBe(['attributes' => ['name' => 'CLASSIC BURGER XL'], 'old' => ['name' => 'Classic Burger XL']]);
});

it('logs a rename only when the name changes, and counts a case-only change as a rename', function () {
    config(['logging.channels.catalog' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    $item = MenuItem::factory()->create(['name' => 'Classic Burger']);
    $messages = fn () => array_map(fn ($r) => $r->message, Log::channel('catalog')->getLogger()->getHandlers()[0]->getRecords());

    $same = $this->patchJson("/api/v1/menu-items/{$item->ulid}", ['name' => 'Classic Burger'])->assertOk();
    expect($same->json())->assertNoIntegerIds();
    expect($messages())->toBe([]);

    $case = $this->patchJson("/api/v1/menu-items/{$item->ulid}", ['name' => 'classic burger'])->assertOk();
    expect($case->json())->assertNoIntegerIds();
    expect($messages())->toBe(['Menu item renamed']);
    expect($item->fresh()->name)->toBe('classic burger');
});

it('replaces the recipe and removes the old lines', function () {
    $i = menuIngredients();
    $item = classicBurger($i);

    $response = $this->putJson("/api/v1/menu-items/{$item->ulid}/recipe", ['lines' => [
        ['ingredient_id' => $i['beef']->ulid, 'quantity' => 180],
        ['ingredient_id' => $i['bun']->ulid, 'quantity' => 1],
    ]])->assertOk();

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('data.is_sellable'))->toBeTrue();
    expect(collect($response->json('data.recipe'))->map(fn ($l) => [$l['ingredient']['name'], $l['quantity']])->all())
        ->toBe([['Beef', 180], ['Bun', 1]]);

    expect($item->recipeLines()->count())->toBe(2);
    expect($item->recipeLines()->where('ingredient_id', $i['cheese']->id)->exists())->toBeFalse();
});

it('records old and new lines in the recipe.replaced audit entry with the request channel', function () {
    $i = menuIngredients();
    $item = classicBurger($i);

    $this->putJson("/api/v1/menu-items/{$item->ulid}/recipe", ['lines' => [
        ['ingredient_id' => $i['beef']->ulid, 'quantity' => 180],
        ['ingredient_id' => $i['bun']->ulid, 'quantity' => 1],
    ]], ['X-Patty-Channel' => 'ui'])->assertOk();

    $entry = Activity::where('event', 'recipe.replaced')->firstOrFail();

    expect($entry->subject_id)->toBe($item->id)
        ->and($entry->description)->toBe('Recipe for Classic Burger updated')
        ->and($entry->properties['channel'])->toBe('ui')
        ->and($entry->properties['old'])->toBe([
            ['ingredient' => 'Beef', 'ingredient_id' => $i['beef']->ulid, 'quantity' => 150],
            ['ingredient' => 'Bun', 'ingredient_id' => $i['bun']->ulid, 'quantity' => 1],
            ['ingredient' => 'Cheese', 'ingredient_id' => $i['cheese']->ulid, 'quantity' => 20],
        ])
        ->and($entry->properties['new'])->toBe([
            ['ingredient' => 'Beef', 'ingredient_id' => $i['beef']->ulid, 'quantity' => 180],
            ['ingredient' => 'Bun', 'ingredient_id' => $i['bun']->ulid, 'quantity' => 1],
        ]);
});

it('writes the recipe.replaced entry on create with an empty old list (F3)', function () {
    $i = menuIngredients();

    $this->postJson('/api/v1/menu-items', classicBurgerPayload($i))->assertCreated();

    $entry = Activity::where('event', 'recipe.replaced')->firstOrFail();
    expect($entry->properties['old'])->toBe([])
        ->and($entry->properties['new'])->toHaveCount(3);
});

it('replaces a recipe atomically: a failing audit write leaves the old lines intact', function () {
    config(['logging.default' => 'null']);
    $i = menuIngredients();
    $item = classicBurger($i);
    $before = $item->recipeLines()->orderBy('id')->get(['id', 'ingredient_id', 'quantity'])->toArray();

    // The audit entry is the last write inside the transaction, after the old lines are deleted and the new ones inserted.
    Activity::creating(fn () => throw new RuntimeException('audit write failed'));

    try {
        $this->putJson("/api/v1/menu-items/{$item->ulid}/recipe", ['lines' => [
            ['ingredient_id' => $i['beef']->ulid, 'quantity' => 180],
            ['ingredient_id' => $i['bun']->ulid, 'quantity' => 1],
        ]])->assertStatus(500);
    } finally {
        Activity::flushEventListeners();
    }

    // Same rows, same primary keys: the deletes and inserts were rolled back, not redone.
    expect($item->recipeLines()->orderBy('id')->get(['id', 'ingredient_id', 'quantity'])->toArray())->toBe($before);
    expect(Activity::where('event', 'recipe.replaced')->count())->toBe(0);
});

it('refuses an empty or missing lines array on replace and keeps the old recipe', function () {
    $i = menuIngredients();
    $item = classicBurger($i);

    $this->putJson("/api/v1/menu-items/{$item->ulid}/recipe", ['lines' => []])
        ->assertStatus(422)->assertJsonValidationErrorFor('lines', 'errors');
    $this->putJson("/api/v1/menu-items/{$item->ulid}/recipe", [])
        ->assertStatus(422)->assertJsonValidationErrorFor('lines', 'errors');

    expect($item->recipeLines()->count())->toBe(3);
});

it('paginates the list sorted by name', function () {
    foreach (range(1, 30) as $n) {
        MenuItem::factory()->create(['name' => sprintf('Burger %02d', $n)]);
    }

    $first = $this->getJson('/api/v1/menu-items')->assertOk()
        ->assertJsonCount(25, 'data')
        ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 25, 'total' => 30, 'last_page' => 2, 'has_more' => true]);
    expect($first->json())->assertNoIntegerIds();
    expect($first->json('data.0.name'))->toBe('Burger 01');

    $second = $this->getJson('/api/v1/menu-items?page=2')->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.pagination.has_more', false);
    expect($second->json())->assertNoIntegerIds();
    expect($second->json('data.4.name'))->toBe('Burger 30');
});

it('loads recipes for the whole list in a fixed number of queries', function () {
    $i = menuIngredients();
    $countQueries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/menu-items?per_page=100')->assertOk();

        return count(DB::getQueryLog());
    };

    classicBurger($i);
    $withOne = $countQueries();

    foreach (range(1, 10) as $n) {
        $item = MenuItem::factory()->create(['name' => "Extra {$n}"]);
        RecipeLine::factory()->create(['menu_item_id' => $item->id, 'ingredient_id' => $i['beef']->id, 'quantity' => 5]);
    }

    expect($countQueries())->toBe($withOne);
});

it('returns 404 for a numeric id in the URL on every item route', function () {
    $item = MenuItem::factory()->create(['name' => 'Classic Burger']);
    $i = menuIngredients();
    $lines = ['lines' => [['ingredient_id' => $i['beef']->ulid, 'quantity' => 5]]];

    $this->getJson("/api/v1/menu-items/{$item->id}")->assertNotFound()->assertJsonPath('code', 'not_found');
    $this->patchJson("/api/v1/menu-items/{$item->id}", ['name' => 'New Name'])->assertNotFound();
    $this->putJson("/api/v1/menu-items/{$item->id}/recipe", $lines)->assertNotFound();
});

it('reports is_sellable false for an item with no recipe lines', function () {
    $item = MenuItem::factory()->create(['name' => 'Plain Bun']);

    $response = $this->getJson("/api/v1/menu-items/{$item->ulid}")->assertOk();

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('data.is_sellable'))->toBeFalse()
        ->and($response->json('data.recipe'))->toBe([]);
});
