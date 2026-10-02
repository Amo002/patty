<?php

/*
 * The purchasing and POS pages (PTY-19). They are Blade shells, so what can go wrong on the server side is the
 * route, the layout, the script wiring and the headers. Behaviour (receive, replay, 422 inline) runs in the
 * browser against the API, which the API feature tests already cover.
 */

const DETAIL_ULID = '01m3y4c7bq7kr6rm97mpbxqnwq';

dataset('purchasing pages', [
    'purchase orders list' => ['/purchase-orders', 'js/pages/purchase-orders.js', 'x-data="purchaseOrders"'],
    'new purchase order' => ['/purchase-orders/new', 'js/pages/purchase-order-new.js', 'x-data="purchaseOrderNew"'],
    'purchase order detail' => ['/purchase-orders/'.DETAIL_ULID, 'js/pages/purchase-order.js', 'x-data="purchaseOrder('],
    'pos simulator' => ['/pos', 'js/pages/pos.js', 'x-data="posSimulator"'],
]);

it('serves each page inside the shared layout with its own script', function (string $url, string $script, string $component) {
    $this->get($url)
        ->assertOk()
        ->assertSee('/css/app.css', false)
        ->assertSee('id="confirm-dialog"', false)
        ->assertSee('/css/pages/purchasing.css', false)
        ->assertSee('/js/pages/purchasing-shared.js', false)
        ->assertSee($script, false)
        ->assertSee($component, false);
})->with('purchasing pages');

it('sends no-store and the security headers on each page', function (string $url) {
    $response = $this->get($url);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('Referrer-Policy'))->toBe('same-origin');
})->with(['/purchase-orders', '/purchase-orders/new', '/purchase-orders/'.DETAIL_ULID, '/pos']);

it('puts the order id on the detail page for the script to load', function () {
    // The page loads the order through the API with this id; it is the only value the server hands the script.
    $this->get('/purchase-orders/'.DETAIL_ULID)->assertSee(DETAIL_ULID, false);
});

it('rejects a detail url that is not a ULID', function (string $value) {
    $this->get('/purchase-orders/'.$value)->assertNotFound();
})->with([
    'a number' => '12',
    'a word' => 'abc',
    'too short' => '01m3y4c7bq7kr6rm97mpbxqnw',
    'too long' => '01m3y4c7bq7kr6rm97mpbxqnwq0',
    'letters outside the alphabet' => '01m3y4c7bq7kr6rm97mpbxqnwu',
    'markup' => '%3Cscript%3E',
]);

it('keeps the new-order form on its own route, not the detail page', function () {
    $this->get('/purchase-orders/new')->assertOk()->assertSee('x-data="purchaseOrderNew"', false);
});

it('gives the receive dialog one quantity input per unit so each row follows its ingredient', function () {
    $html = $this->get('/purchase-orders/'.DETAIL_ULID)->getContent();

    // The unit-by-ingredient partial renders g, ml and piece variants behind x-if; the dialog allows 0 for completed lines.
    expect($html)->toContain("x-if=\"line.unit === 'g'\"")
        ->toContain("x-if=\"line.unit === 'ml'\"")
        ->toContain("x-if=\"line.unit === 'piece'\"")
        ->toContain('\u0022allowZero\u0022:true');
});

it('never renders API data as HTML in the purchasing pages (S5)', function () {
    $files = collect([
        ...File::allFiles(resource_path('views/pages')),
        ...File::allFiles(public_path('js/pages')),
        public_path('css/pages/purchasing.css'),
    ])->map(fn ($file) => $file instanceof SplFileInfo ? $file : new SplFileInfo($file));

    $offenders = $files
        ->filter(fn (SplFileInfo $file) => preg_match('/x-html|innerHTML|outerHTML|insertAdjacentHTML|document\.write/', file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file) => $file->getFilename());

    expect($files->count())->toBeGreaterThan(5);
    expect($offenders->values()->all())->toBe([]);
});

it('sends the pos channel from the POS simulator and the ui default from the purchase order pages', function () {
    expect(file_get_contents(public_path('js/pages/pos.js')))->toContain("channel: 'pos'");

    foreach (['purchase-orders', 'purchase-order', 'purchase-order-new'] as $page) {
        expect(file_get_contents(public_path("js/pages/{$page}.js")))->not->toContain('channel:');
    }
});
