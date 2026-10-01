{{--
    Image with a fixed aspect ratio (so lazy loading never shifts the layout) and an icon fallback (D-036).
    The icon sits underneath, the image on top: if the file is missing or fails, the image hides itself and the icon shows.
    For Alpine data pass x-bind:src="item.image_url" instead of src. The URL is bound as an attribute, never as HTML (S5).
--}}
@props(['src' => null, 'alt' => '', 'ratio' => '4 / 3'])
<div {{ $attributes->only('class')->class(['photo']) }} style="--ratio: {{ $ratio }}">
    <x-icon name="image" />
    <img @if ($src) src="{{ $src }}" @endif alt="{{ $alt }}" loading="lazy" decoding="async"
         onerror="this.hidden = true" {{ $attributes->whereStartsWith('x-') }}>
</div>
