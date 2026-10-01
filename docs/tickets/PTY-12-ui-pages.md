# PTY-12 UI: Dashboard and Activity

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3b Build frontend |
| Status | To Do |
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
