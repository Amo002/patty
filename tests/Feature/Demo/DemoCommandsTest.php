<?php

use App\Models\Ingredient;
use App\Support\DemoTools;
use Illuminate\Support\Facades\DB;

/*
| patty:demo:clear, seed and reset (PTY-22, D-037, S13). The suite runs with APP_ENV=testing, which is
| "not local", so the refusal is the default here and local is simulated by switching the environment.
*/

/*
| migrate:fresh ends with VACUUM, which SQLite refuses inside a transaction, and RefreshDatabase wraps every
| test in one. Leaving it first is safe: each test boots its own in-memory database (see tests/Pest.php).
*/
beforeEach(fn () => DB::rollBack());

function runAsLocal(): void
{
    app()->detectEnvironment(fn () => 'local');
}

it('refuses every command outside local without --force-env, and changes nothing', function (string $command) {
    DemoTools::seed();
    $before = DemoTools::counts();

    $this->artisan($command, ['--force' => true])->assertFailed();

    expect(DemoTools::counts())->toBe($before);
})->with(['patty:demo:clear', 'patty:demo:seed', 'patty:demo:reset']);

it('runs outside local when --force-env is given', function () {
    $this->artisan('patty:demo:seed', ['--force' => true, '--force-env' => true])->assertSuccessful();

    expect(DemoTools::counts()['ingredients'])->toBe(12);
});

it('asks before clearing and does nothing when the answer is no', function () {
    runAsLocal();
    DemoTools::seed();

    $this->artisan('patty:demo:clear')
        ->expectsConfirmation('This deletes ALL data, including the stock history. Continue?', 'no')
        ->assertFailed();

    expect(DemoTools::isEmpty())->toBeFalse();
});

it('clears when confirmed', function () {
    runAsLocal();
    DemoTools::seed();

    $this->artisan('patty:demo:clear')
        ->expectsConfirmation('This deletes ALL data, including the stock history. Continue?', 'yes')
        ->assertSuccessful();

    expect(DemoTools::isEmpty())->toBeTrue();
});

it('skips the question with --force', function () {
    runAsLocal();

    $this->artisan('patty:demo:reset', ['--force' => true])->assertSuccessful();

    expect(DemoTools::counts()['purchase_orders'])->toBe(7);
});

it('fails with the 409 message when seeding over data', function () {
    runAsLocal();
    Ingredient::factory()->create();

    $this->artisan('patty:demo:seed', ['--force' => true])->assertFailed();

    expect(DemoTools::counts()['ingredients'])->toBe(1);
});
