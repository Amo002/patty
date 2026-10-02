<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
| Every Feature and Unit test boots the Laravel app and runs against a fresh
| in-memory SQLite database (see phpunit.xml). No mocking of the domain.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
| D-031 / T25: integer database ids must never reach a client. Walks decoded
| JSON recursively and fails on any key `id` or `*_id` holding an integer.
| Usage: expect($response->json())->assertNoIntegerIds();
*/
expect()->extend('assertNoIntegerIds', function () {
    $walk = function (mixed $node, string $path) use (&$walk): void {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            $isIdKey = is_string($key) && ($key === 'id' || str_ends_with($key, '_id'));

            if ($isIdKey && is_int($value)) {
                Assert::fail("Integer id leaked at {$path}.{$key} = {$value}");
            }

            $walk($value, "{$path}.{$key}");
        }
    };

    $walk($this->value, '$');

    return $this;
});
