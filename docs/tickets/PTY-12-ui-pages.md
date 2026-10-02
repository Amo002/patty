# PTY-12 UI: Dashboard and Activity

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3b Build frontend |
| Status | Awaiting Mohamad |
| Weight | M |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 (code) + Opus 5.5 design reviewer with Claude in Chrome |
| Branch | `PTY-12-ui-pages` |
| Release | v0.4.0 |
| Depends on | PTY-10, PTY-11 (design approved 2026-10-01) |

> **Scope after Q-012:** this ticket is the Dashboard and the Activity page. Catalogue pages are PTY-18; Purchase Orders, Receive and the POS Simulator are PTY-19. All acceptance criteria below that belong to those pages move there. Screens are built in code from the approved design system (D-043). Behaviour rules U1 to U12 in [ui.md](../ui.md) already apply (skeletons, lazy loading, states, confirmations, errors, time, numbers, no `x-html`).

## Goal

A manager can do everything in the brief from the UI without touching the API. Every page reads and writes through `/api/v1`.

## Covers

NFR-5, FR-1 to FR-6 from the UI side. Manual QA-1 to QA-7.

## Acceptance criteria

- [ ] Dashboard: KPI row, live stock table (negative flagged), open orders with progress bars and outstanding, freshness stamp, auto-refresh
- [ ] Ingredients: list with on-hand, create dialog, history drawer per ingredient
- [ ] Suppliers: list, create dialog
- [ ] Menu & Recipes: list, create item, recipe editor (add/remove lines, unit shown next to the quantity)
- [ ] Purchase Orders:
  - list with status filter;
  - create (supplier plus lines);
  - detail page with per-line ordered, received and outstanding;
  - only valid actions shown (from the API's allowed actions);
  - "Receive delivery" dialog prefilled with outstanding.
- [ ] POS Simulator: pick a menu item and quantity, sell (auto-generated `pos_reference`), see deductions and any negatives, and a "resend last" button to demonstrate idempotency
- [ ] Dashboard and Ingredients show **Incoming** next to On hand (D-020)
- [ ] Activity: a global page (filterable) and an Activity panel on the PO detail page, from `GET /activity` (D-021)
- [ ] Every 422 shown inline next to its field. A 409 shows a notice and refreshes the view. Success shown as a toast.
- [ ] All eight user satisfaction rules in [design.md](../design.md#user-satisfaction-rules) are met:
  - in-flight disable;
  - confirm dialogs;
  - prefilled delivery with its limit;
  - kitchen-language errors;
  - guiding empty states;
  - readable numbers;
  - visible freshness;
  - kitchen-ready layout.
- [ ] Design reviewer pass: screenshots at 1440 px and 820 px recorded in this ticket, findings resolved
- [ ] A GIF of the partial-delivery flow and the POS sale flow, recorded with Claude in Chrome, saved to `docs/media/` for the README
- [ ] `routes/web.php` serves only page shells. Feature tests confirm each page returns 200.

## Tests required

- [ ] Each page route returns 200 and carries `Cache-Control: no-store`

## Additions from D-035 to D-038

- [ ] Ingredients: photo thumbnails; a tolerance column ("+5% / -5%, max +2 kg", with "default" shown muted); an edit dialog for the overrides.
- [ ] Menu: photos.
- [ ] PO lines show max receivable, min to complete and the under-delivered or over-received tags. The receive dialog shows each line's limit in the user's chosen unit.
- [ ] Every quantity uses `<x-quantity-input>` and the `units.js` formatter.

## Builder notes

Built by Sonnet 5.5. The builder stopped at the weekly usage limit after its three commits; the orchestrator (Opus 5.5) checked the branch was complete, merged develop and ran the suite.

- Dashboard (`/`): KPI row (E28), stock with Incoming and a Negative tag (E27), open orders with progress and outstanding (E16 `status=open`), freshness stamp, one 10 s poll for all panels. Open orders load when scrolled near (U2).
- Activity (`/activity`): the E29 trail with channel tags, expandable changes, a type select, and `?subject_type=&subject_id=` for one record. Entries have no id (D-031), so rows are keyed by their own fields.
- `public/js/pages/live-list.js`: one paged list shared by both pages. Every request goes through one queue, so a refresh asked for during a load waits instead of being dropped (the bug found in the PTY-18 review).
- Try it card (D-027): five steps, ticked from E29 events after the tour started (server clock, from E27 `generated_at`), a number change seen between two polls, and an Activity visit. Read only; progress is kept in localStorage.
- `welcome.blade.php` placeholder removed.

## Review

The Opus 5.5 code reviewer could not run (weekly usage limit), so this is a lighter orchestrator review, not the full mutation pass the backend tickets had. Checked:

- Every field the pages read exists in the API: E27 `ingredient.id`, `on_hand`, `incoming`, `is_negative`; E16 `progress_percent`, `short_closed`, `lines.*.quantity_outstanding`; E29 `event`, `channel`, `subject`, `changes`, `created_at`.
- The tour's event names match `Audit::record` (`purchase_order.sent`, `delivery.recorded`, `sale.recorded`), and the POS page sends `X-Patty-Channel: pos`, so step 3 can tick.
- No `x-html`, `{!! !!}` or `innerHTML` in either page (S5).
- With a type filter that matches nothing loaded yet, the list keeps loading pages through the scroll sentinel until it finds matches or ends, then shows the empty state. Accepted: E29 has no type-only filter.

No defects found. Not done, and why:

- Design reviewer pass with screenshots at 1440 px and 820 px: not run (no agent budget). Mohamad checks the pages by hand in the browser before merging.
- GIFs for the README: moved to PTY-14.
- The rest of the acceptance criteria above belong to PTY-18 and PTY-19 after Q-012.

Depends on PTY-10 (PR #22): E27, E28 and E29 exist only once it is merged. Merge #22 first.
