# PTY-11 Design system and app shell

| Field | Value |
|---|---|
| Type | Story |
| Status | To Do |
| Weight | M |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 |
| Branch | `PTY-11-design-system` |
| Release | v0.4.0 |
| Depends on | PTY-2 |

## Goal

The look, motion and layout from [design.md](../design.md), as one CSS file and a Blade layout every page uses.

## Acceptance criteria

- [ ] `public/css/app.css`: tokens, base, layout, all components in design.md, motion, reduced-motion
- [ ] `public/vendor/alpine.min.js`, vendored with its version in a comment, loaded with `defer`
- [ ] `public/js/api.js`: a tiny `fetch` wrapper (JSON, 422 to field errors, 404 handling) and `poll(fn, ms)` that pauses when the tab is hidden and refetches on `pageshow` and `visibilitychange`
- [ ] `resources/views/layouts/app.blade.php`: sidebar nav with the active state, page header, content slot, toast region, cross-document View Transitions
- [ ] A component preview page at `/_styleguide` (local env only) showing every component
- [ ] No emoji. Inline SVG icons only.
- [ ] Keyboard focus visible on every interactive element

## Out of scope

Feature pages (PTY-12).
