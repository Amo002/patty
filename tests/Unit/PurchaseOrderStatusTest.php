<?php

use App\Enums\PurchaseOrderStatus;

/*
| The transition map is the whole defence against bad order states, so every
| (from, to) pair of the four states is pinned: exactly three are legal.
*/
$pairs = [];
$allowed = [
    'draft->sent',
    'sent->received',
    'received->closed',
];

foreach (PurchaseOrderStatus::cases() as $from) {
    foreach (PurchaseOrderStatus::cases() as $to) {
        $pairs["{$from->value}->{$to->value}"] = [$from, $to, in_array("{$from->value}->{$to->value}", $allowed, true)];
    }
}

it('allows only the three forward moves', function (PurchaseOrderStatus $from, PurchaseOrderStatus $to, bool $expected) {
    expect($from->canTransitionTo($to))->toBe($expected);
})->with($pairs);

it('covers all 16 pairs with exactly 3 allowed', function () use ($pairs) {
    expect($pairs)->toHaveCount(16)
        ->and(collect($pairs)->filter(fn (array $pair) => $pair[2]))->toHaveCount(3);
});

it('lists the targets of each status', function () {
    expect(PurchaseOrderStatus::Draft->allowedTransitions())->toBe([PurchaseOrderStatus::Sent])
        ->and(PurchaseOrderStatus::Sent->allowedTransitions())->toBe([PurchaseOrderStatus::Received])
        ->and(PurchaseOrderStatus::Received->allowedTransitions())->toBe([PurchaseOrderStatus::Closed])
        ->and(PurchaseOrderStatus::Closed->allowedTransitions())->toBe([]);
});

it('labels received as partially received (D-012)', function () {
    expect(PurchaseOrderStatus::Received->label())->toBe('Partially received');
});
