{{--
    Page assets for the purchasing pages, pushed into the layout's `scripts` stack (which sits in <head>).
    The layout's $asset helper is not visible from a child view, so the same filemtime cache-buster is repeated here.
    Order matters: deferred scripts run in document order, so the shared helpers come before the page script.
--}}
@php
    $v = fn (string $path) => '/'.$path.'?v='.(is_file(public_path($path)) ? filemtime(public_path($path)) : '0');
@endphp
<link rel="stylesheet" href="{{ $v('css/pages/purchasing.css') }}">
<script defer src="{{ $v('js/pages/purchasing-shared.js') }}"></script>
<script defer src="{{ $v('js/pages/'.$script.'.js') }}"></script>
