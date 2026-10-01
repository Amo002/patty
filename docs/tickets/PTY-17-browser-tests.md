# PTY-17 Browser tests (stretch)

| Field | Value |
|---|---|
| Type | Story |
| Phase | 4 Review and testing |
| Status | To Do |
| Weight | M |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 |
| Branch | `PTY-17-browser-tests` |
| Release | v1.0.0 |
| Depends on | PTY-12 |

**Stretch.** First to cut if time runs short (see progress.md). D-026. Final scope (which flows, which viewports) is confirmed in phase 2b alongside the UI ticket split (Q-012).

## Goal

Prove the main UI flows end to end in a real browser, without making Node a requirement for running the app or the core tests.

## Acceptance criteria

- [ ] `composer require pestphp/pest-plugin-browser --dev`, plus a `package.json` with `playwright` only (devDependency), and `npx playwright install chromium`
- [ ] `phpunit.xml` `defaultTestSuite="Unit,Feature"`. A `Browser` suite for `tests/Browser`.
- [ ] `composer test:browser` runs the browser suite
- [ ] `.gitignore` includes `tests/Browser/Screenshots` and `node_modules`
- [ ] CI: a second job, `Browser tests`, that sets up Node, installs Playwright Chromium and runs `composer test:browser`. It is **not** a required check, so a flaky browser cannot block a merge.
- [ ] README: how to run the browser suite (optional)

## Tests required (tests/Browser)
Each one ends with `assertNoJavaScriptErrors()`.
- [ ] Partial delivery: create a PO, send it, receive part of it. The PO shows Partially received with the right outstanding, and the dashboard stock rises.
- [ ] Complete delivery: receive the rest. The PO shows Closed and leaves open orders.
- [ ] POS simulator: sell until cheese is negative. The Negative flag is visible on the dashboard.
- [ ] Over-delivery input beyond 5% shows the inline error, and stock is unchanged.
- [ ] Every page on a mobile viewport passes `assertNoAccessibilityIssues()`.
