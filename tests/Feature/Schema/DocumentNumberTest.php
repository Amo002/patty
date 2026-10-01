<?php

use App\Services\DocumentNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // Pin the clock so the year in the expected strings is stable.
    $this->travelTo(Carbon::parse('2026-06-15 10:00:00', 'UTC'));
});

it('numbers documents sequentially per type', function () {
    $numbers = app(DocumentNumber::class);

    expect($numbers->next('PO'))->toBe('PO-2026-0001')
        ->and($numbers->next('PO'))->toBe('PO-2026-0002');
});

it('keeps the counters of different types independent and pads them per type', function () {
    $numbers = app(DocumentNumber::class);

    $numbers->next('PO');
    $numbers->next('PO');

    expect($numbers->next('GRN'))->toBe('GRN-2026-0001')
        ->and($numbers->next('SALE'))->toBe('SALE-2026-000001');
});

it('restarts at 0001 in a new UTC calendar year', function () {
    $numbers = app(DocumentNumber::class);

    $this->travelTo(Carbon::parse('2026-12-31 23:59:00', 'UTC'));
    expect($numbers->next('PO'))->toBe('PO-2026-0001');

    $this->travelTo(Carbon::parse('2027-01-01 00:01:00', 'UTC'));
    expect($numbers->next('PO'))->toBe('PO-2027-0001')
        ->and($numbers->next('PO'))->toBe('PO-2027-0002');
});

it('returns a valid number when the caller opened no transaction of its own', function () {
    // The test harness holds one outer transaction; next() must still work and
    // must open its own nested one, which is what makes it safe standalone.
    $levelBefore = DB::transactionLevel();

    expect(app(DocumentNumber::class)->next('PO'))->toBe('PO-2026-0001')
        ->and(DB::transactionLevel())->toBe($levelBefore);
});

it('gives the same number back when the numbered document rolls back', function () {
    $numbers = app(DocumentNumber::class);

    try {
        DB::transaction(function () use ($numbers) {
            expect($numbers->next('PO'))->toBe('PO-2026-0001');

            throw new RuntimeException('document insert failed');
        });
    } catch (RuntimeException) {
        // Expected: the failed write rolls the counter back with it.
    }

    expect($numbers->next('PO'))->toBe('PO-2026-0001');
});

it('rejects an unknown document type', function () {
    app(DocumentNumber::class)->next('INVOICE');
})->throws(InvalidArgumentException::class, 'Unknown document type');
