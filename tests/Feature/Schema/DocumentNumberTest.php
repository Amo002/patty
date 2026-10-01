<?php

use App\Services\DocumentNumber;
use Illuminate\Support\Carbon;

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

it('rejects an unknown document type', function () {
    app(DocumentNumber::class)->next('INVOICE');
})->throws(InvalidArgumentException::class, 'Unknown document type');
