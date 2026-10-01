# PTY-11 Design system and app shell

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3b Build frontend |
| Status | To Do |
| Weight | M |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 (code) + Opus 5.5 design reviewer with Claude in Chrome |
| Branch | `PTY-11-design-system` |
| Release | v0.4.0 |
| Depends on | Design approved 2026-10-01 (PTY-23) |

> Implements the **approved** design system: tokens and components exactly as in design.md (D-041) and the design canvas, plus the brand from D-042.

## Goal

The look, motion and layout from [design.md](../design.md), as one CSS file and a Blade layout every page uses.

## Acceptance criteria

- [ ] `public/css/app.css`: tokens, base, layout, all components in design.md, motion, reduced-motion
- [ ] `public/vendor/alpine.min.js`, vendored with its version in a comment, loaded with `defer`
- [ ] `public/js/api.js`: a small `fetch` wrapper. It always sends `X-Patty-Channel: ui` and reads the envelope: `success` gives `data`; 422 gives field errors keyed by `errors`; 409 gives a notice and a refresh; others give a notice with `message`. Also `poll(fn, ms)`, which pauses when the tab is hidden and refetches on `pageshow` and `visibilitychange`.
- [ ] Icons: `scripts/build-icons.php` converts the listed Hugeicons free icons (MIT) to `resources/icons/*.svg`, run once, output committed with `resources/icons/LICENSE`. A Blade component `<x-icon name="..." />` (D-025).
- [ ] `resources/views/layouts/app.blade.php`: sidebar nav with the active state, page header, content slot, toast region, cross-document View Transitions
- [ ] A component preview page at `/_styleguide` (local env only) showing every component
- [ ] No emoji. Hugeicons only, through `<x-icon>`.
- [ ] Keyboard focus visible on every interactive element

## Out of scope

Feature pages (PTY-12).

## Additions from D-035 to D-038

- [ ] `public/js/units.js`, the single place for unit display and input conversion, implementing every row of the ui.md conversion table. String-based decimal parsing, no float multiplication.
- [ ] A `<x-quantity-input>` Blade component: number field plus a g/kg or ml/L switch, sending integer base units, with the precision error before submit.
- [ ] Image component: shows `image_url` (lazy-loaded, fixed aspect ratio, so no layout shift) with a Hugeicons fallback.

## Additions from D-041 and D-042 (approved design)

- [ ] `public/css/app.css` implements every token in design.md for light and dark: `prefers-color-scheme` plus a `data-theme` attribute set by the identity-chip toggle and remembered in localStorage (wrapped in try/catch).
- [ ] Inter variable `woff2` (OFL) downloaded once into `public/fonts/` with its `OFL.txt`; `@font-face` with `font-display: swap`.
- [ ] Layout includes the favicon links: `/brand/favicon.svg`, plus the PNG fallbacks from `scripts/build-brand-pngs.php` (PHP GD, same geometry as the SVG).
- [ ] Session loader as in design.md (Brand): shown on the first load of a session, 900 ms max, never blocking, reduced-motion aware. Busy buttons use the stacking motion.
- [ ] Sidebar shows `logo.svg` (light) or the mono mark with the wordmark in `--text` (dark).
