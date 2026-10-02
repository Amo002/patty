<?php

use Illuminate\Support\Facades\Route;

// Placeholder dashboard (PTY-11). PTY-12 swaps the view for the real page.
Route::view('/', 'welcome');

// A review aid, not a feature: every component in every state. Absent outside local, so it never ships (D-041).
if (app()->environment('local')) {
    Route::view('/_styleguide', 'styleguide');
}

// PTY-19 purchasing and POS pages
// Pages are shells: they load and change data only through /api/v1. The detail page takes a ULID (D-031),
// constrained to Crockford base32 so "new" and any junk value never reach the view.
Route::view('/purchase-orders', 'pages.purchase-orders');
Route::view('/purchase-orders/new', 'pages.purchase-order-new');
Route::get('/purchase-orders/{ulid}', fn (string $ulid) => view('pages.purchase-order', ['ulid' => $ulid]))
    ->where('ulid', '[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}');
