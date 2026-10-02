<?php

/*
 * The Dashboard and Activity pages (PTY-12). They are Blade shells, so what can go wrong on the server side is
 * the route, the layout, the script wiring and the headers. The behaviour (polling, flashing, the tour ticking)
 * runs in the browser against the API, whose own tests live under tests/Feature/Visibility.
 */

dataset('dashboard and activity pages', [
    'dashboard' => ['/', 'js/pages/dashboard.js', 'x-data="dashboard"'],
    'activity' => ['/activity', 'js/pages/activity.js', 'x-data="activity"'],
]);

it('serves each page inside the shared layout with its own scripts', function (string $url, string $script, string $component) {
    $this->get($url)
        ->assertOk()
        ->assertSee('/css/app.css', false)
        ->assertSee('id="confirm-dialog"', false)
        ->assertSee('/css/pages/dashboard.css', false)
        ->assertSee('/js/pages/live-list.js', false)
        ->assertSee($script, false)
        ->assertSee($component, false)
        // The live stamp ("Updated N s ago") only renders when the page declares itself live.
        ->assertSee('x-data="freshness"', false);
})->with('dashboard and activity pages');

it('loads the shared list helper before the page script, and both before Alpine', function (string $url, string $script) {
    $html = $this->get($url)->getContent();

    // Deferred scripts run in document order, so the order in the markup is the order they execute.
    expect(strpos($html, 'js/pages/live-list.js'))->toBeLessThan(strpos($html, $script))
        ->and(strpos($html, $script))->toBeLessThan(strpos($html, 'vendor/alpine.min.js'));
})->with([
    'dashboard' => ['/', 'js/pages/dashboard.js'],
    'activity' => ['/activity', 'js/pages/activity.js'],
]);

it('sends no-store and the security headers on each page', function (string $url) {
    $response = $this->get($url);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('Referrer-Policy'))->toBe('same-origin');
})->with(['/', '/activity']);

it('shows the dashboard panels and the guided tour with its five steps', function () {
    $this->get('/')
        ->assertSee('Stock')
        ->assertSee('Open orders')
        ->assertSee('Incoming is ordered and not yet delivered.')
        ->assertSee('id="stock-panel"', false)
        ->assertSee('id="tour-title"', false);

    // The five steps are data in dashboard.js, so check they point where the tour promises.
    $script = file_get_contents(public_path('js/pages/dashboard.js'));
    foreach (["'/pos'", "'/activity'", "'#stock-panel'", "'/purchase-orders/new'"] as $href) {
        expect($script)->toContain($href);
    }
    expect(preg_match_all('/\bn: [1-5],/', $script))->toBe(5);
});

it('has replaced the old placeholder dashboard', function () {
    $this->get('/')->assertDontSee('Dashboard arrives in PTY-12');

    expect(resource_path('views/welcome.blade.php'))->not->toBeFile();
});

it('lets a purchase order page link to its own activity by query string', function () {
    // The page reads the filter in the browser; the server only has to serve the same shell for any query.
    $this->get('/activity?subject_type=purchase_order&subject_id=01m3y4c7bq7kr6rm97mpbxqnwq')
        ->assertOk()
        ->assertSee('x-data="activity"', false);
});

it('never renders API data as HTML in the dashboard and activity files (S5, U12)', function () {
    $files = [
        resource_path('views/pages/dashboard.blade.php'),
        resource_path('views/pages/activity.blade.php'),
        public_path('js/pages/dashboard.js'),
        public_path('js/pages/activity.js'),
        public_path('js/pages/live-list.js'),
        public_path('css/pages/dashboard.css'),
    ];

    $offenders = collect($files)
        ->filter(fn (string $path) => preg_match('/x-html|innerHTML|outerHTML|insertAdjacentHTML|document\.write|\{!!/', file_get_contents($path)) === 1)
        ->map(fn (string $path) => basename($path));

    expect($offenders->values()->all())->toBe([]);
});

it('keys activity rows by their own fields and never by an id the entries do not have', function () {
    $script = file_get_contents(public_path('js/pages/activity.js'));

    // E29 entries carry no id (D-031): keying or de-duplicating on one would collapse every row into one.
    expect($script)->toContain('_key')->and($script)->not->toContain('entry.id');
});
