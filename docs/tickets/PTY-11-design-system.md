# PTY-11 Design system and app shell

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3b Build frontend |
| Status | To Do |
| Weight | M |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 (code) + Fable 5.1 design reviewer with Claude in Chrome |
| Branch | `PTY-11-design-system` |
| Release | v0.4.0 |
| Depends on | **Phase 2b design approved** (progress.md) |

> The tokens and components here are provisional until phase 2b. The approved design system replaces them.

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
