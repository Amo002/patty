{{--
    The page script and the catalogue stylesheet. Expects $script: the file name under public/js/pages.
    The stylesheet goes to the layout's styles stack, so it loads in <head> after app.css.
    A changing query string means an edit shows up on reload without a build step.
--}}
@php
    $version = fn (string $path) => '/'.$path.'?v='.(is_file(public_path($path)) ? filemtime(public_path($path)) : '0');
@endphp
@push('styles')
    <link rel="stylesheet" href="{{ $version('css/pages/catalogue.css') }}">
@endpush
@push('scripts')
    <script defer src="{{ $version('js/pages/catalogue-common.js') }}"></script>
    <script defer src="{{ $version('js/pages/'.$script.'.js') }}"></script>
@endpush
