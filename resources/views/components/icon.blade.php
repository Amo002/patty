{{--
    Inlines one of the Hugeicons converted by scripts/build-icons.php (D-025).
    Inlined, not <img>, so the stroke follows currentColor in both themes.
    The name is checked against a strict pattern, because it becomes a file path and the output is unescaped.
    An unknown name throws everywhere except production, so a typo fails loudly in development and in tests.
    In production it logs a warning and renders nothing, because a missing glyph must not take a page down.
--}}
@props(['name'])
@php
    abort_unless(preg_match('/^[a-z0-9-]+$/', $name) === 1, 500, 'Invalid icon name.');
    $path = resource_path("icons/{$name}.svg");
    if (! is_file($path)) {
        throw_unless(app()->isProduction(), \InvalidArgumentException::class, "Unknown icon [{$name}]. Add it to scripts/build-icons.php.");
        \Illuminate\Support\Facades\Log::warning("Unknown icon [{$name}]");
    }
    $svg = is_file($path) ? file_get_contents($path) : '';
    $attrs = $attributes->merge(['class' => 'icon', 'aria-hidden' => 'true', 'focusable' => 'false'])->toHtml();
    $svg = str_replace('<svg ', '<svg '.$attrs.' ', $svg);
@endphp
{!! $svg !!}
