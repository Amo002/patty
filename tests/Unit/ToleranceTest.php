<?php

use App\Models\PurchaseOrderLine;
use App\Support\Tolerance;

// D-035 arithmetic. Expected values are worked by hand: allowance = intdiv(ordered * bps, 10000),
// clamped by the cap; shortfall = intdiv(ordered * under_bps, 10000).
it('computes the receivable window from ordered quantity', function (
    int $ordered, ?int $cap, int $expectedMax, int $expectedMin
) {
    $tolerance = new Tolerance(overBps: 500, underBps: 500, overCap: $cap);

    expect($tolerance->maxReceivable($ordered))->toBe($expectedMax)
        ->and($tolerance->minToComplete($ordered))->toBe($expectedMin);
})->with([
    'beef 1000 g, 5% = 50 under the cap' => [1000, 2000, 1050, 950],
    'beef 50000 g, 5% = 2500 clamped to the 2000 cap' => [50000, 2000, 52000, 47500],
    'buns 10, 5% rounds down to 0 so exact only' => [10, null, 10, 10],
    'oil 20000 ml, 5% = 1000 under the cap' => [20000, 2000, 21000, 19000],
    'no cap lets the full percentage through' => [50000, null, 52500, 47500],
]);

it('treats zero basis points as exact quantities only', function () {
    $tolerance = new Tolerance(overBps: 0, underBps: 0, overCap: 2000);

    expect($tolerance->maxReceivable(1000))->toBe(1000)
        ->and($tolerance->minToComplete(1000))->toBe(1000);
});

it('never lets a line complete with nothing received', function (int $underBps, int $ordered) {
    $tolerance = new Tolerance(overBps: 0, underBps: $underBps, overCap: null);

    expect($tolerance->minToComplete($ordered))->toBe(1);
})->with([
    '100% under tolerance on 1000' => [10000, 1000],
    'a single piece ordered' => [500, 1],
]);

it('labels a PO line tolerance as a snapshot', function () {
    $line = PurchaseOrderLine::factory()->make();

    expect($line->tolerance()->source)->toBe(Tolerance::SOURCE_SNAPSHOT);
});

it('applies a zero cap as no over-delivery even when the percentage allows some', function () {
    $tolerance = new Tolerance(overBps: 500, underBps: 500, overCap: 0);

    expect($tolerance->maxReceivable(1000))->toBe(1000);
});
