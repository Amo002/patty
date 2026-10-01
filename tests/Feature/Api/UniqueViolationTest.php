<?php

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/*
| PTY-26 / G6 / D-024: Rule::unique is the friendly check and the NOCASE unique
| index is the backstop. These tests skip the FormRequest on purpose, which is
| exactly what a lost race looks like: validation passed, the index says no.
| The table and route are test-only scaffolding, never registered in routes/.
*/

beforeEach(function () {
    // The exception is reported; keep that out of storage/logs.
    config(['logging.default' => 'null']);

    Schema::create('unique_probe_items', function ($table) {
        $table->id();
        $table->string('name')->collation('NOCASE');
        $table->unique('name');
    });

    Route::middleware('api')->post('api/v1/__unique/items', function () {
        DB::table('unique_probe_items')->insert(['name' => request('name')]);

        return response()->json(['ok' => true], 201);
    });
});

it('PTY-26a: a duplicate that slips past validation returns 409 conflict, not 500', function () {
    $this->postJson('/api/v1/__unique/items', ['name' => 'Beef'])->assertCreated();

    // Different case on purpose: the NOCASE index must catch it (G6).
    $response = $this->postJson('/api/v1/__unique/items', ['name' => 'beef']);

    $response->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'conflict')
        ->assertJsonPath('message', 'This record already exists or was just created by another request. Refresh and try again.')
        ->assertJsonPath('errors', []);
    expect(DB::table('unique_probe_items')->count())->toBe(1);
});

it('PTY-26b: the response never leaks SQL or the constraint name', function () {
    $this->postJson('/api/v1/__unique/items', ['name' => 'Beef']);

    $body = $this->postJson('/api/v1/__unique/items', ['name' => 'BEEF'])->getContent();

    expect($body)
        ->not->toContain('SQLSTATE')
        ->not->toContain('UNIQUE constraint')
        ->not->toContain('unique_probe_items')
        ->not->toContain('insert into');
});

it('PTY-26c: the violation is still reported so the detail reaches the log', function () {
    Exceptions::fake();
    $this->postJson('/api/v1/__unique/items', ['name' => 'Beef']);

    $this->postJson('/api/v1/__unique/items', ['name' => 'beef'])->assertStatus(409);

    Exceptions::assertReported(UniqueConstraintViolationException::class);
});
