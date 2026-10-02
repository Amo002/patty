@php
    // A changing query string means a stylesheet or script edit shows up on reload, without a build step to hash filenames.
    $asset = fn (string $path) => '/'.$path.'?v='.(is_file(public_path($path)) ? filemtime(public_path($path)) : '0');

    $nav = [
        ['/', 'Dashboard', 'dashboard'],
        ['/ingredients', 'Ingredients', 'ingredients'],
        ['/suppliers', 'Suppliers', 'supplier'],
        ['/menu', 'Menu & Recipes', 'menu'],
        ['/purchase-orders', 'Purchase Orders', 'purchase-order'],
        ['/pos', 'POS Simulator', 'pos'],
        ['/activity', 'Activity', 'activity'],
    ];

    // PTY-22: demo tools exist only in local (S13); "Load demo data" is only offered on an empty system.
    $demoTools = \App\Support\DemoTools::enabled();
    $demoEmpty = $demoTools && \App\Support\DemoTools::isEmpty();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>@hasSection('title')@yield('title') | Patty @else Patty @endif</title>

    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/brand/favicon-32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/brand/apple-touch-icon.png">

    {{-- Runs before first paint, so a saved theme never flashes the wrong colours, and the session loader is decided once per session (D-042). --}}
    <script>
        (function () {
            try {
                var theme = localStorage.getItem('patty-theme');
                if (theme === 'light' || theme === 'dark') document.documentElement.setAttribute('data-theme', theme);
            } catch (e) {}
            try {
                if (!sessionStorage.getItem('patty-loader')) {
                    sessionStorage.setItem('patty-loader', '1');
                    document.documentElement.classList.add('show-loader');
                }
            } catch (e) {}
        })();
    </script>

    <link rel="preload" href="/fonts/InterVariable.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ $asset('css/app.css') }}">
    {{-- PTY-18: page stylesheets --}}
    @stack('styles')

    {{-- Order matters: deferred scripts run in document order, and Alpine must start last (D-003). --}}
    <script defer src="{{ $asset('js/units.js') }}"></script>
    <script defer src="{{ $asset('js/api.js') }}"></script>
    <script defer src="{{ $asset('js/ui.js') }}"></script>
    {{-- PTY-22: demo actions, local only --}}
    @if ($demoTools)<script defer src="{{ $asset('js/demo.js') }}"></script>@endif
    @stack('scripts')
    <script defer src="{{ $asset('vendor/alpine.min.js') }}"></script>
</head>
<body x-data="{ navOpen: false }" :class="{ 'nav-open': navOpen }" @keydown.escape.window="navOpen = false">
    {{-- Session loader: first load of a session only. pointer-events none and a 900 ms cap mean it can never block (D-042). --}}
    <div id="session-loader" class="session-loader" aria-hidden="true">
        <x-logo-mark class="loader-mark" />
        <span class="loader-word">Patty</span>
    </div>

    <a class="visually-hidden" href="#main">Skip to content</a>

    <div class="app">
        <aside class="sidebar" id="sidebar" aria-label="Main">
            <a class="brand" href="/" aria-label="Patty, dashboard">
                <img class="brand-light" src="/brand/logo.svg" alt="Patty" width="148" height="40">
                <span class="brand-dark" aria-hidden="true"><x-logo-mark variant="mono" /> Patty</span>
            </a>
            <nav class="nav">
                @foreach ($nav as [$href, $label, $icon])
                    @php($active = $href === '/' ? request()->path() === '/' : request()->is(ltrim($href, '/').'*'))
                    <a class="nav-link" href="{{ $href }}" @if ($active) aria-current="page" @endif>
                        <x-icon :name="$icon" />
                        <span>{{ $label }}</span>
                    </a>
                @endforeach
            </nav>
        </aside>
        <div class="sidebar-scrim" @click="navOpen = false"></div>

        <div class="main">
            <header class="topbar">
                <button type="button" class="btn btn-secondary btn-icon menu-button" aria-label="Open menu"
                        :aria-expanded="navOpen" aria-controls="sidebar" @click="navOpen = !navOpen">
                    <x-icon name="menu-toggle" />
                </button>

                <div class="identity" x-data="themeMenu" @click.outside="open = false" @keydown.escape="open = false">
                    <button type="button" class="identity-chip" title="No login, by design. See the README."
                            aria-haspopup="true" :aria-expanded="open" @click="open = !open">
                        <span class="avatar" aria-hidden="true">R</span>
                        <span>Restaurant manager</span>
                        <x-icon name="chevron-down" class="icon-sm" />
                    </button>
                    <div class="menu" x-show="open" x-cloak>
                        <span class="menu-title">Theme</span>
                        <div class="segmented" role="group" aria-label="Theme">
                            <button type="button" :aria-pressed="pref === 'light'" @click="set('light')"><x-icon name="sun" class="icon-sm" /> Light</button>
                            <button type="button" :aria-pressed="pref === 'dark'" @click="set('dark')"><x-icon name="moon" class="icon-sm" /> Dark</button>
                            <button type="button" :aria-pressed="pref === 'system'" @click="set('system')">System</button>
                        </div>
                        {{-- PTY-22: local-only demo data actions, each behind a confirm dialog (D-037) --}}
                        @if ($demoTools)
                            <div class="stack stack-sm" x-data="demoTools" data-demo-tools="1" data-empty="{{ $demoEmpty ? '1' : '0' }}">
                                <span class="menu-title">Demo data</span>
                                <button type="button" class="btn btn-secondary" @click="reset()">Reset demo</button>
                                <button type="button" class="btn btn-secondary" @click="clear()">Clear all data</button>
                                <button type="button" class="btn btn-secondary" :disabled="!isEmpty" @click="seed()">Load demo data</button>
                            </div>
                        @endif
                        <p class="hint">No login, by design. See the README.</p>
                    </div>
                </div>
            </header>

            <main class="content" id="main">
                <div class="banner" x-data="introBanner" x-show="visible" x-cloak>
                    <x-icon name="info" />
                    <span class="grow">Single-branch demo. No login needed. Start with the guided tour.</span>
                    @stack('banner-actions')
                    {{-- PTY-22 --}}
                    @if ($demoTools)
                        <span x-data="demoTools" data-empty="{{ $demoEmpty ? '1' : '0' }}"><button type="button" class="btn btn-secondary" @click="reset()">Reset demo data</button></span>
                    @endif
                    <button type="button" class="btn btn-ghost btn-icon" aria-label="Dismiss" @click="dismiss()">
                        <x-icon name="close" />
                    </button>
                </div>

                <div class="page-header">
                    <h1>@yield('title')</h1>
                    <div class="page-meta">
                        @yield('actions')
                        @hasSection('live')
                            <span class="freshness" x-data="freshness" @patty:refreshed.window="touch()"
                                  :class="{ 'is-pulsing': pulsing }" :data-stale="stale" role="status">
                                <span x-text="label">Waiting for data</span>
                            </span>
                        @endif
                    </div>
                </div>

                @yield('content')
            </main>
        </div>
    </div>

    <div class="toast-region" x-data>
        <template x-for="toast in $store.toasts.items" :key="toast.id">
            <div class="toast" :data-tone="toast.tone" :role="toast.tone === 'error' ? 'alert' : 'status'">
                <div class="toast-body">
                    <span x-text="toast.message"></span>
                    <span class="toast-ref" x-show="toast.requestId" x-text="'Request id: ' + toast.requestId"></span>
                </div>
                <button type="button" class="btn btn-ghost btn-icon" aria-label="Dismiss notification" @click="$store.toasts.dismiss(toast.id)">
                    <x-icon name="close" />
                </button>
            </div>
        </template>
    </div>

    {{-- The shared confirm dialog (U6). Pages call Patty.confirm({...}); see public/js/ui.js. --}}
    <dialog id="confirm-dialog" class="dialog" aria-labelledby="confirm-title" x-data
            @close="$store.confirm.closed()"
            @cancel="$store.confirm.busy && $event.preventDefault()"
            @click="if ($event.target === $el) $store.confirm.cancel()">
        <div class="dialog-head"><h2 id="confirm-title" x-text="$store.confirm.title"></h2></div>
        <div class="dialog-body stack stack-sm">
            <p x-text="$store.confirm.message" x-show="$store.confirm.message"></p>
            <ul x-show="$store.confirm.lines.length">
                <template x-for="(line, index) in $store.confirm.lines" :key="index + ':' + line">
                    <li x-text="line"></li>
                </template>
            </ul>
            <p class="inline-error" role="alert" x-show="$store.confirm.error">
                <x-icon name="alert" />
                <span x-text="$store.confirm.error"></span>
            </p>
        </div>
        <div class="dialog-foot">
            <button type="button" class="btn btn-secondary" :disabled="$store.confirm.busy" @click="$store.confirm.cancel()">Cancel</button>
            <button type="button" class="btn"
                    :class="$store.confirm.tone === 'danger' ? 'btn-danger' : 'btn-primary'"
                    :disabled="$store.confirm.busy" :aria-busy="$store.confirm.busy" @click="$store.confirm.accept()">
                <span class="stack-spinner" aria-hidden="true"><i></i><i></i><i></i></span>
                <span x-text="$store.confirm.confirmLabel"></span>
            </button>
        </div>
    </dialog>

    <script>
        // Backstop for the loader's own CSS animation: gone after 900 ms even if the animation is disabled.
        setTimeout(function () {
            var loader = document.getElementById('session-loader');
            if (loader) loader.remove();
            document.documentElement.classList.remove('show-loader');
        }, 900);
    </script>
</body>
</html>
