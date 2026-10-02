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

## Builder notes

Where things live:

- **Tokens, components, motion:** `public/css/app.css`, in numbered sections (tokens, base, layout, components, motion, reduced motion). Dark is declared twice on purpose: under `prefers-color-scheme` (guarded by `:root:not([data-theme="light"])`) and under `:root[data-theme="dark"]`, because CSS cannot share one block between a media query and a selector.
- **Font:** Inter 4.1 variable `InterVariable.woff2` from the official rsms/inter release (https://github.com/rsms/inter/releases/download/v4.1/Inter-4.1.zip, `web/InterVariable.woff2`), with `OFL.txt` and a source note in `public/fonts/README.md`. `font-display: swap`.
- **Alpine:** v3.14.9, `cdn.min.js` from jsdelivr, saved unmodified to `public/vendor/alpine.min.js` with a header comment. Loaded with `defer`, last, after `units.js`, `api.js`, `ui.js` and any page scripts pushed to the `scripts` stack, so components registered on `alpine:init` exist when Alpine starts.
- **JS modules (plain scripts on `window.Patty`):** `units.js` (conversion with string arithmetic; self-check page `units.test.html`, 36 checks), `api.js` (fetch wrapper, envelope, `poll`), `ui.js` (toasts, confirm dialog, theme menu, freshness stamp, intro banner, quantity input, `x-flash` directive, status labels, time helpers).
- **Icons:** `scripts/build-icons.php` (Hugeicons free 4.3.5 from unpkg, MIT) wrote `resources/icons/*.svg` and a `LICENSE`. `<x-icon>` inlines them and validates the name against `^[a-z0-9-]+$`.
- **Brand PNGs:** `scripts/build-brand-pngs.php` (GD, 8x supersampled) draws the same three layers as `favicon.svg`.
- **Layout:** `resources/views/layouts/app.blade.php`. Pages `@extends` it and fill `title`, `actions`, `content`, and optionally `live` (shows the freshness stamp). `banner-actions` is a stack for the local-only "Reset demo data" button (PTY-22).
- **Placeholder home:** `resources/views/welcome.blade.php` is the "Dashboard arrives in PTY-12" page. PTY-12 replaces it.

How the session loader avoids blocking:

- A tiny inline script in `<head>` checks `sessionStorage` once per session and adds `show-loader` to `<html>`. Without that class the loader is `display: none`.
- The loader has `pointer-events: none`, so clicks pass through even while it is visible.
- Its CSS animation ends at 900 ms (`visibility: hidden`), and an inline script at the end of `<body>` removes the element at 900 ms as a backstop. The page content is rendered underneath the whole time.
- Layer styles are the final state and keyframes only describe the start, so under reduced motion the static logo shows for 900 ms and is then removed.

Decisions and notes for review:

- Server errors are re-expressed with a regex over `<number> g|ml`. It relies on the backend writing quantities in the message in that form.
- `api.js` rejects with an `ApiError` for every non-2xx (422 included, `handled=false`) so callers have one error path; 409 and others show a notice before rejecting.
- `poll` refetches on `pageshow` only when `event.persisted`, because a normal load also fires `pageshow` and would fetch twice.
- Pieces display as `pc` for exactly 1 and `pcs` otherwise (ui.md only shows the plural).
- The status mapping exists twice (PHP in `<x-status-pill>`, JS in `Patty.statusMeta`) because Alpine templates cannot call Blade.
- I could not drive the pages in a browser from this environment (Chrome could not reach the local server), so visual review and Alpine behaviour (x-modelable prefill, dialog transitions) still need the design reviewer's pass on `/_styleguide`.

## Reviewer findings

Code review by Opus 5.5, 2026-10-02. Verdict: approve once F1 to F3 are fixed (all small; PTY-12 copies these patterns). Pint passes, 83 tests pass, all 36 `units.test.html` checks pass under node, the tokens match design.md in light and dark exactly, Alpine matches upstream 3.14.9 byte for byte, and running `build-brand-pngs.php` again leaves the PNGs unchanged.

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| F1 | Medium | public/js/ui.js:292 | The page cannot re-express a server 422 in the unit the user typed (D-038, ui.md last row): `quantity-change` sends `{ base, error }` but not `mode`, and nothing calls `units.rewriteError`. Add `mode` to the event detail (or a `rewrite(message)` method on the component) and show it in the styleguide. | Fixed. `quantity-change` now carries `mode`; `Patty.fieldErrors(errors, modes)` and a component `rewrite()` re-express 422 messages; styleguide has a fake 422. |
| F2 | Medium | resources/views/components/quantity-input.blade.php:9 | The id comes from `Str::random` when the Blade renders, so a component inside an Alpine `x-for` (delivery and PO lines in PTY-12) gives every row the same id: the label `for` and `aria-describedby` all point at the first row. Allow a bound id (`x-bind:id`) or build the id in Alpine. | Fixed. The component uses Alpine `x-id` and `$id('qty')` for the label, input and message ids (or a fixed `id` prop); the styleguide shows two x-for rows. |
| F3 | Medium | public/css/app.css:413 | The busy state stops pointer clicks only (`pointer-events: none`). Enter or Space on a focused busy button still fires `click`. The confirm dialog guards this in `accept()`, but the styleguide pattern (`:aria-busy="busy"` alone) does not, and rule 1 says buttons *disable*. Document and use `:disabled="busy"` together with `:aria-busy`. | Fixed. Busy buttons use `:disabled` plus `:aria-busy`; the label turns transparent so width is kept; the confirm dialog buttons are disabled while busy; the pattern is documented in the CSS and demoed. |
| F4 | Low | public/js/ui.js:300 | `parseInput` accepts `-1`, `-1.5` and `0`, so the quantity input reports no error for them. Every quantity field (PO line, delivery, recipe) needs at least 1. Refuse values of 0 or below before submit (the server's 422 is still the backstop). | Fixed. `parseInput` refuses 0 and negatives ("Enter a quantity above 0"); `allow-zero` prop opts in to 0; 6 new self-check rows (42 total). |
| F5 | Low | public/css/app.css:529 | `.segmented button` is 40 px tall, under the 44 px tap target in rule 8 (unit switch and theme switch). | Fixed. Segmented buttons are `--tap` (44 px) tall. |
| F6 | Low | public/css/app.css:334 | `max-width: 820px` collapses the sidebar at exactly 820 px. ui.md says "below 820 px". Either use 819.98px or record the call (a drawer may well be the better choice on an 820 px iPad). | Fixed. Breakpoint is `max-width: 819.98px`. |
| F7 | Low | public/js/ui.js:157 | Confirm with `run`: a 409 or 500 is reported twice (a toast from api.js plus the inline error), and after a 409 the dialog stays open on stale data. When `e.handled` is true, close the dialog instead of showing the error. | Fixed. A handled error closes the dialog (no duplicate); a 409 also runs the new `onConflict` option; unhandled errors still show once inline. Styleguide demos both. |
| F8 | Low | resources/views/layouts/app.blade.php:157 | `x-for` uses `:key="line"`, so two identical summary lines collide. Key by index. | Fixed. Keyed by `index:line`. |
| F9 | Low | resources/views/components/photo.blade.php:10 | `onerror="this.hidden = true"` is never undone, so a later valid `src` (after a refetch) stays hidden. Clear `hidden` on `load`. | Fixed. `onload` clears `hidden`. |
| F10 | Low | resources/views/components/icon.blade.php:10 | A misspelled icon name renders nothing, silently. Fail loudly outside production. | Fixed. An unknown icon throws outside production and logs a warning and renders nothing in production; documented in the component; two tests. |
| F11 | Info | public/js/ui.js:25 | The duplicated status map is acceptable. The API already returns `status_label` (api.md), so Alpine pills could show that and map only the tone, which leaves one copy of the wording. | Accepted. Two copies of the wording stay for now; PTY-12 may use the API `status_label` and map only the tone. |
| F12 | Info | tests/Feature/Web/ShellTest.php:15 | Mutation check: removing the PNG favicon and apple-touch-icon links still passes. Assert `/brand/favicon-32.png` and `/brand/apple-touch-icon.png` too. | Fixed. The test asserts the PNG favicon and apple-touch-icon links. |
| F13 | Info | public/js/api.js:163 | `poll` refetches on `visibilitychange` but not on window `focus` (U3 says "on focus"), which matters with two windows side by side. Optional. | Fixed. `poll` also refetches on window `focus`; focus and visibility share a 1 s guard so one switch is one fetch. |

Accepted deviations: the `menu-toggle` icon; `Restaurant01` and `CreditCardPos`; `pc` and `pcs`; `pageshow` only when `persisted`; one ApiError path with 422 unhandled; the `rewriteError` regex (it matches api.md's "Beef: 1,100 g is above the 1,050 g limit." and the longer "receiving 500 g would bring the total to 1,100 g" form); no reset button until PTY-22; the loader mechanics; `SESSION_DRIVER=database` (unchanged from develop, needs `migrate` before the first page view, which the README already requires).
