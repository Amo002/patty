<?php

use Illuminate\Support\Facades\Route;
use Illuminate\View\ViewException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * The app shell (PTY-11). These cover what the layout promises every page: the favicon, the no-store and
 * security headers, a styleguide that exists only in local, and the S5 rule against x-html.
 */

it('renders the dashboard inside the shared layout', function () {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee('/brand/favicon.svg', false)
        ->assertSee('/brand/favicon-32.png', false)
        ->assertSee('/brand/apple-touch-icon.png', false)
        ->assertSee('/css/app.css', false)
        ->assertSee('x-data="dashboard"', false)
        ->assertSee('id="confirm-dialog"', false);

    // The sidebar lists every page of the inventory and marks the current one.
    foreach (['/ingredients', '/suppliers', '/menu', '/purchase-orders', '/pos', '/activity'] as $href) {
        $response->assertSee('href="'.$href.'"', false);
    }
    $response->assertSee('aria-current="page"', false);
});

it('sends no-store and the security headers on web pages', function () {
    $response = $this->get('/');

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('Referrer-Policy'))->toBe('same-origin');
});

it('does not serve the styleguide outside local', function () {
    // The test environment is "testing", so the route was never registered.
    $this->get('/_styleguide')->assertNotFound();
});

it('serves the styleguide in local', function () {
    // Routes are registered once at boot, so switch the environment and load routes/web.php again, as booting in local would.
    app()->detectEnvironment(fn () => 'local');
    Route::middleware('web')->group(base_path('routes/web.php'));

    $this->get('/_styleguide')
        ->assertOk()
        ->assertSee('Styleguide')
        ->assertSee('Quantity inputs')
        ->assertSee('Status pills');
});

it('renders every status pill with a text label and a tone', function () {
    $html = view('styleguide')->render();

    foreach (['Draft', 'Sent', 'Partially received', 'Closed', 'Short', 'Negative'] as $label) {
        expect($html)->toContain('>'.$label.'</span>');
    }
    expect($html)->toContain('data-tone="warn"')->toContain('data-tone="danger"')->toContain('data-tone="ok"');
});

it('never uses x-html in a view (S5)', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($file) => str_contains($file->getContents(), 'x-html'))
        ->map(fn ($file) => $file->getRelativePathname());

    expect($offenders->all())->toBe([]);
});

it('never assigns innerHTML in the UI scripts (S5)', function () {
    $offenders = collect(File::allFiles(public_path('js')))
        ->filter(fn ($file) => str_contains($file->getContents(), 'innerHTML'))
        ->map(fn ($file) => $file->getFilename());

    expect($offenders->all())->toBe([]);
});

it('has an svg for every icon the layout and components use', function () {
    foreach (['dashboard', 'ingredients', 'supplier', 'menu', 'purchase-order', 'pos', 'activity', 'plus', 'edit', 'trash',
        'check', 'alert', 'info', 'close', 'chevron-down', 'search', 'refresh', 'sun', 'moon', 'image', 'menu-toggle'] as $icon) {
        expect(resource_path("icons/{$icon}.svg"))->toBeFile();
    }
});

it('refuses an icon name that could escape the icons folder', function () {
    expect(fn () => view('components.icon', ['name' => '../../.env'])->render())->toThrow(HttpException::class);
});

it('fails loudly on an unknown icon outside production', function () {
    expect(fn () => view('components.icon', ['name' => 'not-an-icon'])->render())
        ->toThrow(ViewException::class, 'Unknown icon [not-an-icon]');
});

it('renders nothing, without failing, for an unknown icon in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(trim(view('components.icon', ['name' => 'not-an-icon'])->render()))->toBe('');
});

it('gives each quantity input its own Alpine id scope instead of a render-time id', function () {
    $html = view('components.quantity-input', ['unit' => 'g', 'label' => 'Beef'])->render();

    expect($html)->toContain('x-id="[\'qty\']"')->toContain(':for="$id(\'qty\')"')->toContain(':id="$id(\'qty\')"');
});
