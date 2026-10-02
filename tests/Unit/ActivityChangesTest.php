<?php

use App\Services\StockQuery;

/*
| E29: spatie's {attributes, old} reshaped into {field: [old, new]}, with internal fields dropped.
*/

it('pairs each changed field with its old and new value', function () {
    expect(StockQuery::reshapeChanges(['status' => 'sent'], ['status' => 'draft']))
        ->toBe(['status' => ['draft', 'sent']]);
});

it('gives null as the old value on a create', function () {
    expect(StockQuery::reshapeChanges(['name' => 'Beef', 'unit' => 'g'], []))
        ->toBe(['name' => [null, 'Beef'], 'unit' => [null, 'g']]);
});

it('drops integer keys and the image path (D-031)', function () {
    $changes = StockQuery::reshapeChanges(
        ['id' => 7, 'supplier_id' => 3, 'image_path' => 'ingredients/beef.webp', 'name' => 'Beef'],
        [],
    );

    expect($changes)->toBe(['name' => [null, 'Beef']]);
});
