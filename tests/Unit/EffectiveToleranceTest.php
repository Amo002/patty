<?php

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Support\Tolerance;

it('gives an ingredient with no overrides the default for its unit', function (Unit $unit, ?int $cap) {
    $tolerance = Ingredient::factory()->unit($unit)->make()->effectiveTolerance();

    expect($tolerance->overBps)->toBe(500)
        ->and($tolerance->underBps)->toBe(500)
        ->and($tolerance->overCap)->toBe($cap)
        ->and($tolerance->source)->toBe(Tolerance::SOURCE_DEFAULT);
})->with([
    'grams' => [Unit::Gram, 2000],
    'millilitres' => [Unit::Millilitre, 2000],
    'pieces have no cap' => [Unit::Piece, null],
]);

it('merges a partial override per field and reports the ingredient as the source', function () {
    $tolerance = Ingredient::factory()
        ->unit(Unit::Gram)
        ->withTolerance(overBps: 1000)
        ->make()
        ->effectiveTolerance();

    expect($tolerance->overBps)->toBe(1000)
        ->and($tolerance->underBps)->toBe(500)
        ->and($tolerance->overCap)->toBe(2000)
        ->and($tolerance->source)->toBe(Tolerance::SOURCE_INGREDIENT);
});

it('honours a zero override instead of falling back to the default', function () {
    $tolerance = Ingredient::factory()
        ->unit(Unit::Gram)
        ->withTolerance(overBps: 0, underBps: 0)
        ->make()
        ->effectiveTolerance();

    expect($tolerance->overBps)->toBe(0)
        ->and($tolerance->underBps)->toBe(0);
});

it('lets an ingredient add a cap to a unit that has none', function () {
    $tolerance = Ingredient::factory()
        ->unit(Unit::Piece)
        ->withTolerance(overCap: 3)
        ->make()
        ->effectiveTolerance();

    expect($tolerance->overCap)->toBe(3)
        ->and($tolerance->overBps)->toBe(500);
});
