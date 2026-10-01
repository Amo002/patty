<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Every Feature and Unit test boots the Laravel app and runs against a fresh
| in-memory SQLite database (see phpunit.xml). No mocking of the domain.
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');
