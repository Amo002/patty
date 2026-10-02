<?php

/*
 * The catalogue pages (PTY-18): ingredients, suppliers, menu. These are Blade shells that load their data
 * from the API in the browser, so what can be proven here is the shell: the page renders inside the layout,
 * loads its own script, carries the headers every page must, and never renders API data as HTML (S5).
 */

dataset('catalogue pages', [
    'ingredients' => ['/ingredients', 'ingredients', 'Ingredients'],
    'suppliers' => ['/suppliers', 'suppliers', 'Suppliers'],
    'menu' => ['/menu', 'menu', 'Menu &amp; Recipes'],
]);

it('renders each catalogue page inside the layout with its own script', function (string $url, string $script, string $title) {
    $response = $this->get($url);

    $response->assertOk()
        ->assertSee('id="confirm-dialog"', false)          // the layout's shared confirm dialog
        ->assertSee('/js/pages/catalogue-common.js', false) // the shared paged list
        ->assertSee('/js/pages/'.$script.'.js', false)
        ->assertSee('/css/pages/catalogue.css', false)
        ->assertSee($title, false)
        ->assertSee('x-data="'.$script.'Page"', false);

    // The sidebar marks the page as current.
    $response->assertSee('aria-current="page"', false);
})->with('catalogue pages');

it('sends no-store and the security headers on each catalogue page', function (string $url) {
    $response = $this->get($url);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
    expect($response->headers->get('Referrer-Policy'))->toBe('same-origin');
})->with(['/ingredients', '/suppliers', '/menu']);

it('ships the script file every catalogue page loads', function () {
    foreach (['catalogue-common', 'ingredients', 'suppliers', 'menu'] as $script) {
        expect(public_path("js/pages/{$script}.js"))->toBeFile();
    }
    expect(public_path('css/pages/catalogue.css'))->toBeFile();
});

it('renders API data only with text bindings in the catalogue files (S5)', function () {
    $files = collect([
        ...File::allFiles(resource_path('views/pages')),
        ...File::allFiles(public_path('js/pages')),
    ]);

    // Every way to turn text into markup: Alpine's x-html, the DOM setters, and Blade's unescaped echo.
    $needles = ['x-html', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', '{!!'];

    $offenders = $files
        ->filter(fn ($file) => collect($needles)->contains(fn ($needle) => str_contains($file->getContents(), $needle)))
        ->map(fn ($file) => $file->getFilename());

    expect($files)->not->toBeEmpty();
    expect($offenders->all())->toBe([]);
});

it('gives the recipe editor a quantity input for every unit and keeps recipe lines in g and ml', function () {
    $html = $this->get('/menu')->getContent();

    // One input per unit, chosen by x-if from the ingredient picked on that row (the input fixes its unit at render time).
    foreach (['g', 'ml', 'piece'] as $unit) {
        expect($html)->toContain("line.ingredient.unit === '{$unit}'");
    }
    // D-038: recipe lines start in g and ml, not kg and L.
    // Js::from writes the config with escaped quotes (backslash, u0022), so build the needle the same way.
    $quote = '\\u0022';
    expect(substr_count($html, "{$quote}context{$quote}:{$quote}recipe{$quote}"))->toBe(3);
    expect($html)->not->toContain("{$quote}context{$quote}:{$quote}purchase{$quote}");
});
