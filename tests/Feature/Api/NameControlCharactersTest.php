<?php

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Supplier;

/*
| S16: names reach log lines (an over-delivery logs the ingredient name). A line break in a name
| could forge a second log line, so every name field refuses control characters with a 422.
*/

$forged = "Beef\n[2026-10-02 12:00:00] local.ERROR: forged entry";

const CONTROL_CHAR_MESSAGE = 'The name cannot contain line breaks or other control characters.';

it('refuses a line break in an ingredient name', function () use ($forged) {
    $this->postJson('/api/v1/ingredients', ['name' => $forged, 'unit' => 'g'])
        ->assertStatus(422)
        ->assertJsonPath('errors.name.0', CONTROL_CHAR_MESSAGE);

    expect(Ingredient::query()->count())->toBe(0);
});

it('refuses a control character in a supplier name, on create and on update', function () {
    $this->postJson('/api/v1/suppliers', ['name' => "Dairy\tHills"])->assertStatus(422)->assertJsonPath('errors.name.0', CONTROL_CHAR_MESSAGE);

    $supplier = Supplier::factory()->create(['name' => 'Dairy Hills']);
    $this->patchJson("/api/v1/suppliers/{$supplier->ulid}", ['name' => "Dairy\rHills"])->assertStatus(422)->assertJsonPath('errors.name.0', CONTROL_CHAR_MESSAGE);

    expect($supplier->refresh()->name)->toBe('Dairy Hills');
});

it('refuses a line break in a menu item name, on create and on update', function () use ($forged) {
    $this->postJson('/api/v1/menu-items', ['name' => $forged])->assertStatus(422)->assertJsonPath('errors.name.0', CONTROL_CHAR_MESSAGE);

    $item = MenuItem::factory()->create(['name' => 'Classic Burger']);
    $this->patchJson("/api/v1/menu-items/{$item->ulid}", ['name' => $forged])->assertStatus(422)->assertJsonPath('errors.name.0', CONTROL_CHAR_MESSAGE);

    expect($item->refresh()->name)->toBe('Classic Burger');
});

it('still accepts ordinary names with spaces, accents and punctuation', function () {
    $this->postJson('/api/v1/ingredients', ['name' => "Jalapeño (sliced) - chef's", 'unit' => 'g'])->assertCreated();
});
