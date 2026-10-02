<?php

use App\Models\Supplier;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Spatie\Activitylog\Models\Activity;

/*
| E7 to E10 (PTY-5). Every JSON response is checked with assertNoIntegerIds (D-031).
*/

it('creates a supplier and returns exactly the documented fields', function () {
    $response = $this->postJson('/api/v1/suppliers', [
        'name' => 'Al-Mashreq Meats',
        'email' => 'orders@mashreq.example',
        'phone' => '+962 6 555 0101',
    ])->assertCreated();

    expect($response->json())->assertNoIntegerIds();
    expect(array_keys($response->json('data')))->toBe(['id', 'name', 'email', 'phone', 'created_at']);
    expect($response->json('data.id'))->toBe(Supplier::firstOrFail()->ulid);
    expect($response->json('data.email'))->toBe('orders@mashreq.example');
});

it('creates a supplier with only a name', function () {
    $response = $this->postJson('/api/v1/suppliers', ['name' => 'Corner Bakery'])
        ->assertCreated()
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.phone', null);

    expect($response->json())->assertNoIntegerIds();
});

it('lists suppliers by name and shows one', function () {
    $bakery = Supplier::factory()->create(['name' => 'Corner Bakery']);
    Supplier::factory()->create(['name' => 'Al-Mashreq Meats']);

    $list = $this->getJson('/api/v1/suppliers')->assertOk();
    expect($list->json())->assertNoIntegerIds();
    expect(collect($list->json('data'))->pluck('name')->all())->toBe(['Al-Mashreq Meats', 'Corner Bakery']);
    expect($list->json('meta.pagination.total'))->toBe(2);

    $show = $this->getJson("/api/v1/suppliers/{$bakery->ulid}")->assertOk()->assertJsonPath('data.name', 'Corner Bakery');
    expect($show->json())->assertNoIntegerIds();

    expect($this->getJson('/api/v1/suppliers/1')->assertNotFound()->json())->assertNoIntegerIds();
});

it('paginates suppliers', function () {
    foreach (range(1, 30) as $n) {
        Supplier::factory()->create(['name' => sprintf('Supplier %02d', $n)]);
    }

    $two = $this->getJson('/api/v1/suppliers?per_page=25&page=2')->assertOk();
    expect($two->json())->assertNoIntegerIds();
    expect($two->json('data'))->toHaveCount(5);
    expect($two->json('meta.pagination.has_more'))->toBeFalse();
});

it('rejects a duplicate name regardless of case or padding', function () {
    Supplier::factory()->create(['name' => 'Al-Mashreq Meats']);

    foreach (['al-mashreq meats', ' Al-Mashreq Meats '] as $name) {
        $response = $this->postJson('/api/v1/suppliers', ['name' => $name])->assertStatus(422);
        expect($response->json())->assertNoIntegerIds();
        expect($response->json('errors'))->toHaveKey('name');
    }

    expect(Supplier::count())->toBe(1);
});

it('updates a supplier, keeps its own name valid, and rejects another supplier name', function () {
    $meats = Supplier::factory()->create(['name' => 'Al-Mashreq Meats']);
    Supplier::factory()->create(['name' => 'Corner Bakery']);

    $ok = $this->patchJson("/api/v1/suppliers/{$meats->ulid}", ['name' => 'Al-Mashreq Meats', 'phone' => '+962 6 111 2222'])
        ->assertOk()
        ->assertJsonPath('data.phone', '+962 6 111 2222');
    expect($ok->json())->assertNoIntegerIds();

    $clash = $this->patchJson("/api/v1/suppliers/{$meats->ulid}", ['name' => 'corner bakery'])->assertStatus(422);
    expect($clash->json())->assertNoIntegerIds();
    expect($clash->json('errors'))->toHaveKey('name');
});

it('clears email and phone with null and leaves unsent fields alone', function () {
    $supplier = Supplier::factory()->create(['name' => 'Corner Bakery', 'email' => 'a@b.example', 'phone' => '123']);

    expect($this->patchJson("/api/v1/suppliers/{$supplier->ulid}", ['email' => null])->assertOk()->json())->assertNoIntegerIds();

    $fresh = $supplier->fresh();
    expect($fresh->email)->toBeNull();
    expect($fresh->phone)->toBe('123');
    expect($fresh->name)->toBe('Corner Bakery');
});

it('rejects a bad email', function () {
    foreach (['not-an-email', 'a@', ['x@y.example']] as $email) {
        $response = $this->postJson('/api/v1/suppliers', ['name' => 'Corner Bakery', 'email' => $email])->assertStatus(422);
        expect($response->json())->assertNoIntegerIds();
        expect($response->json('errors'))->toHaveKey('email');
    }

    expect(Supplier::count())->toBe(0);
});

it('rejects a bad phone and accepts the allowed characters', function () {
    foreach (['call me', '+962-abc', str_repeat('1', 31)] as $phone) {
        $response = $this->postJson('/api/v1/suppliers', ['name' => 'Corner Bakery', 'phone' => $phone])->assertStatus(422);
        expect($response->json())->assertNoIntegerIds();
        expect($response->json('errors'))->toHaveKey('phone');
    }

    expect($this->postJson('/api/v1/suppliers', ['name' => 'Corner Bakery', 'phone' => '+962 (6) 555-0101'])->assertCreated()->json())->assertNoIntegerIds();
});

it('accepts supplier names at the length boundaries and rejects outside them', function () {
    expect($this->postJson('/api/v1/suppliers', ['name' => 'ab'])->assertCreated()->json())->assertNoIntegerIds();
    expect($this->postJson('/api/v1/suppliers', ['name' => str_repeat('c', 120)])->assertCreated()->json())->assertNoIntegerIds();

    foreach (['a', str_repeat('d', 121), ['x']] as $name) {
        $response = $this->postJson('/api/v1/suppliers', ['name' => $name])->assertStatus(422);
        expect($response->json())->assertNoIntegerIds();
        expect($response->json('errors'))->toHaveKey('name');
    }
});

it('audits a contact change with the dirty fields only, and stays silent on a no-op', function () {
    config(['logging.channels.catalog' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    $supplier = Supplier::factory()->create(['name' => 'Corner Bakery', 'email' => 'a@b.example', 'phone' => '123']);
    Activity::query()->delete();
    $messages = fn () => array_map(fn ($r) => $r->message, Log::channel('catalog')->getLogger()->getHandlers()[0]->getRecords());

    $same = $this->patchJson("/api/v1/suppliers/{$supplier->ulid}", ['name' => 'Corner Bakery', 'phone' => '123'])->assertOk();
    expect($same->json())->assertNoIntegerIds();
    expect(Activity::count())->toBe(0);
    expect($messages())->toBe([]);

    $changed = $this->patchJson("/api/v1/suppliers/{$supplier->ulid}", ['email' => 'new@b.example'])->assertOk();
    expect($changed->json())->assertNoIntegerIds();
    $entry = Activity::where('event', 'updated')->sole();
    expect($entry->attribute_changes->toArray())->toBe(['attributes' => ['email' => 'new@b.example'], 'old' => ['email' => 'a@b.example']]);
    expect($messages())->toBe(['Supplier updated']);
});

it('writes one created activity row and one catalog log line on create', function () {
    config(['logging.channels.catalog' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);

    $response = $this->postJson('/api/v1/suppliers', ['name' => 'Corner Bakery', 'email' => 'a@b.example'])->assertCreated();
    expect($response->json())->assertNoIntegerIds();

    $entry = Activity::where('event', 'created')->sole();
    expect($entry->subject_id)->toBe(Supplier::firstOrFail()->id);
    expect($entry->attribute_changes->toArray()['attributes'])->toMatchArray(['name' => 'Corner Bakery', 'email' => 'a@b.example']);

    $records = Log::channel('catalog')->getLogger()->getHandlers()[0]->getRecords();
    expect(array_map(fn ($r) => $r->message, $records))->toBe(['Supplier created']);
});
