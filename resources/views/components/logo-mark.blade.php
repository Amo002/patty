{{--
    The stack logo (D-042), same geometry as public/brand/logo-mark.svg.
    variant "layers": each layer is its own element, so the session loader can animate them in sequence.
    variant "mono": three solid shapes in currentColor, used in the sidebar in dark mode.
--}}
@props(['variant' => 'layers'])
@if ($variant === 'mono')
    <svg {{ $attributes }} viewBox="0 0 64 64" fill="currentColor" aria-hidden="true" focusable="false">
        <path d="M10 31a22 19 0 0 1 44 0v1.5a2.5 2.5 0 0 1-2.5 2.5h-39A2.5 2.5 0 0 1 10 32.5z"/>
        <rect x="7" y="38" width="50" height="9" rx="4.5"/>
        <rect x="10" y="50" width="44" height="7" rx="3.5"/>
    </svg>
@else
    <svg {{ $attributes }} viewBox="0 0 64 64" aria-hidden="true" focusable="false">
        <rect class="loader-bottom fill-bun" x="10" y="50" width="44" height="7" rx="3.5"/>
        <rect class="loader-patty fill-patty" x="7" y="38" width="50" height="9" rx="4.5"/>
        <path class="loader-cheese fill-cheese" d="M9 36.5h46v2.5H40.5l-3.5 5-3.5-5H9z"/>
        <g class="loader-top">
            <path class="fill-bun" d="M10 31a22 19 0 0 1 44 0v1.5a2.5 2.5 0 0 1-2.5 2.5h-39A2.5 2.5 0 0 1 10 32.5z"/>
            <ellipse class="fill-seed" cx="25" cy="22" rx="2.2" ry="1.2" transform="rotate(-20 25 22)"/>
            <ellipse class="fill-seed" cx="32" cy="18" rx="2.2" ry="1.2"/>
            <ellipse class="fill-seed" cx="39" cy="22" rx="2.2" ry="1.2" transform="rotate(20 39 22)"/>
        </g>
    </svg>
@endif
