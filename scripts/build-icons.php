<?php

/**
 * One-off icon converter (D-025). Run it by hand: `php scripts/build-icons.php`.
 *
 * Hugeicons free ships as JS arrays of [tag, attributes]. We fetch only the icons
 * the UI uses from unpkg and write plain SVG files, so the app needs no npm at
 * install or runtime (D-003). The output in resources/icons is committed.
 */
const PACKAGE_BASE = 'https://unpkg.com/@hugeicons/core-free-icons@4.3.5/dist/esm/';

// our name => Hugeicons export file (without .js)
$icons = [
    'dashboard' => 'DashboardSquare01Icon',
    'ingredients' => 'Package01Icon',
    'supplier' => 'TruckDeliveryIcon',
    'menu' => 'Restaurant01Icon',
    'purchase-order' => 'File01Icon',
    'pos' => 'CreditCardPosIcon',
    'activity' => 'Activity01Icon',
    'plus' => 'PlusSignIcon',
    'edit' => 'Edit02Icon',
    'trash' => 'Delete02Icon',
    'check' => 'Tick02Icon',
    'alert' => 'Alert02Icon',
    'info' => 'InformationCircleIcon',
    'close' => 'Cancel01Icon',
    'chevron-down' => 'ArrowDown01Icon',
    'search' => 'Search01Icon',
    'refresh' => 'RefreshIcon',
    'sun' => 'Sun03Icon',
    'moon' => 'Moon02Icon',
    'image' => 'Image01Icon',
    'menu-toggle' => 'Menu01Icon',
];

$outDir = __DIR__.'/../resources/icons';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

foreach ($icons as $name => $export) {
    $js = file_get_contents(PACKAGE_BASE.$export.'.js');
    if ($js === false) {
        fwrite(STDERR, "Could not fetch {$export}\n");
        exit(1);
    }

    // Each element is one line: ["tag", { attr: "value", ... }],
    preg_match_all('/\[\s*"(\w+)"\s*,\s*\{([^}]*)\}\s*\]/', $js, $elements, PREG_SET_ORDER);
    if ($elements === []) {
        fwrite(STDERR, "No elements parsed from {$export}\n");
        exit(1);
    }

    $body = '';
    foreach ($elements as [, $tag, $attrSource]) {
        preg_match_all('/(\w+)\s*:\s*"([^"]*)"/', $attrSource, $pairs, PREG_SET_ORDER);
        $attrs = '';
        foreach ($pairs as [, $key, $value]) {
            if ($key === 'key') {
                continue; // a React-only attribute
            }
            // strokeWidth -> stroke-width
            $attr = strtolower(preg_replace('/([A-Z])/', '-$1', $key));
            $attrs .= " {$attr}=\"{$value}\"";
        }
        $body .= "  <{$tag}{$attrs}/>\n";
    }

    // fill none + stroke currentColor on the root, so the icon takes the text colour.
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" '
        ."stroke=\"currentColor\" stroke-width=\"1.5\" stroke-linecap=\"round\" stroke-linejoin=\"round\">\n{$body}</svg>\n";

    file_put_contents("{$outDir}/{$name}.svg", $svg);
    echo "wrote {$name}.svg from {$export}\n";
}
