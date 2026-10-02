<?php

use Illuminate\Support\Facades\Route;

// PTY-12 dashboard and activity pages
Route::view('/', 'pages.dashboard');
Route::view('/activity', 'pages.activity');

// A review aid, not a feature: every component in every state. Absent outside local, so it never ships (D-041).
if (app()->environment('local')) {
    Route::view('/_styleguide', 'styleguide');
}

// PTY-18 catalogue pages
Route::view('/ingredients', 'pages.ingredients');
Route::view('/suppliers', 'pages.suppliers');
Route::view('/menu', 'pages.menu');
