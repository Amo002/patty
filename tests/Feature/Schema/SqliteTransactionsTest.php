<?php

use Illuminate\Support\Facades\DB;

/*
| PTY-31 / D-050: on SQLite, `lockForUpdate()` compiles to nothing, so the services' "read, check,
| then write" transactions are only safe if the transaction takes the write lock when it starts.
| With DEFERRED (Laravel's default) two such transactions both read, then the second one's first
| write fails at once with SQLITE_BUSY (a 500) instead of waiting and seeing the first one's rows.
|
| The test uses a second connection on a real file, because the in-memory test database is
| private to one connection and cannot show a lock held against another.
*/

beforeEach(function () {
    $this->file = tempnam(sys_get_temp_dir(), 'patty-race-').'.sqlite';
    touch($this->file);

    config(['database.connections.race' => array_merge(config('database.connections.sqlite'), [
        'database' => $this->file,
    ])]);
});

afterEach(function () {
    DB::purge('race');
    @unlink($this->file);
});

it('takes the write lock when a transaction begins, before any write', function () {
    $lockedOut = null;

    DB::connection('race')->transaction(function () use (&$lockedOut) {
        // Only a read so far, like the check phase of receiving, selling or refunding.
        DB::connection('race')->select('select 1');

        // A second writer, not willing to wait, must be refused: this transaction already owns the write lock.
        $other = new PDO('sqlite:'.$this->file);
        $other->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $other->setAttribute(PDO::ATTR_TIMEOUT, 0);

        try {
            $other->exec('BEGIN IMMEDIATE');
            $other->exec('ROLLBACK');
            $lockedOut = false;
        } catch (PDOException) {
            $lockedOut = true;
        }
    });

    expect($lockedOut)->toBeTrue();
});

it('waits for a busy database instead of failing at once', function () {
    expect(config('database.connections.sqlite.transaction_mode'))->toBe('IMMEDIATE')
        ->and(config('database.connections.sqlite.busy_timeout'))->toBeGreaterThanOrEqual(1000);
});
