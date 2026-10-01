# Design

Professional, calm, fast. A back-office tool a manager uses during service, so clarity beats decoration. Motion exists to explain change (a number moved, a row arrived, a state advanced), never to entertain.

**No emoji anywhere**: not in the UI, docs, commits or PRs. Icons come from **Hugeicons free** (MIT, stroke-rounded, 1.5 px), converted once to SVG in `resources/icons` and rendered with `<x-icon name="..." />` in `currentColor` (D-025). No UI kit: components are hand-written from the tokens below.

## Principles

1. **Numbers first.** Stock and quantities use tabular numerals, right-aligned, unit in a muted label next to them.
2. **State is colour plus text, never colour alone.** A negative balance is red *and* says "Negative". An order status is a labelled pill.
3. **One primary action per screen.**
4. **Freshness is visible.** Every live view shows "Updated N s ago" with a subtle pulse when it refetches.
5. **Errors say what to do.** The 422 message from the API is shown inline, next to the field it is about.

## Tokens (`public/css/app.css`, `:root`)

### Colour (light; dark via `prefers-color-scheme` if time allows)

| Token | Value | Use |
|---|---|---|
| `--bg` | `#F7F5F2` | Page background (warm paper) |
| `--surface` | `#FFFFFF` | Cards, tables |
| `--surface-2` | `#F0EDE8` | Table header, hover |
| `--border` | `#E4DFD8` | Hairlines |
| `--text` | `#1C1917` | Primary text |
| `--text-muted` | `#78716C` | Labels, units |
| `--accent` | `#C2410C` | Primary actions, focus ring (ember) |
| `--accent-ink` | `#FFFFFF` | Text on accent |
| `--ok` | `#15803D` | Closed, healthy |
| `--warn` | `#B45309` | Partially received, low |
| `--danger` | `#B91C1C` | Negative stock, errors |
| `--info` | `#1D4ED8` | Sent |

Status pills: draft = neutral, sent = info, received = warn ("Partially received"), closed = ok.

### Type

- Family: system UI stack (`ui-sans-serif, system-ui, "Segoe UI", Roboto, sans-serif`). No webfont download, so the app works offline.
- Numbers: `font-variant-numeric: tabular-nums`.
- Scale: 12 / 14 (body) / 16 / 20 / 28 / 36 px. Weights 400, 500, 650.

### Space, radius, shadow

- Space scale: 4, 8, 12, 16, 24, 32, 48 px.
- Radius: 6 px (inputs, pills), 12 px (cards).
- Shadow: one soft elevation for cards and one stronger one for dialogs.

## Motion

| Token | Value | Use |
|---|---|---|
| `--dur-fast` | 120 ms | Hover, press |
| `--dur-base` | 200 ms | Rows entering, pills changing |
| `--dur-slow` | 320 ms | Page transitions, dialogs |
| `--ease-out` | `cubic-bezier(.2,.8,.2,1)` | Entering |
| `--ease-in-out` | `cubic-bezier(.65,0,.35,1)` | Moving |

Techniques, all CSS-native, no animation library:

- **Page transitions:** cross-document View Transitions (`@view-transition { navigation: auto; }`), with the page header and nav as named transition elements so they stay put while content cross-fades.
- **Rows entering:** `@starting-style` fade-and-rise when a new row is inserted.
- **Number change:** when a stock value changes on refetch, it briefly highlights (accent up, danger down) and the digits roll via a short keyframe.
- **State change:** the status pill morphs colour and label on transition. The order's progress bar (received / ordered) animates its width.
- **Dialogs:** native `<dialog>` with `@starting-style` scale-in and a backdrop fade.
- **Reduced motion:** `@media (prefers-reduced-motion: reduce)` sets all durations to 0. No information is carried by motion alone.

## Layout

- Left sidebar nav: Dashboard, Ingredients, Suppliers, Menu & Recipes, Purchase Orders, POS Simulator.
- Content max-width 1200 px. Tables scroll horizontally inside their card on narrow screens. The page itself never scrolls sideways.
- Dashboard: KPI row (ingredients, negative count, open orders, outstanding lines), then the stock table and the open orders list side by side on wide screens, stacked on narrow.

## Components

Button (primary, secondary, ghost, danger), input, select, number input with unit suffix, table, status pill, progress bar, card, dialog, toast (for success; errors stay inline), empty state, "updated N s ago" freshness stamp.

## User satisfaction rules

These are acceptance criteria for every UI page (PTY-12) and are checked in QA-7.

1. **No accidental double actions.** Buttons disable and show a spinner while their request is in flight. A double-click must never record a delivery twice.
2. **Confirm before irreversible actions.** Recording a delivery, sending a PO and short-closing open a dialog with a plain summary ("Add 600 g Beef and 10 Buns to stock?"). Deliveries and sales are never editable, so this is the last chance to catch a mistake.
3. **Smart defaults.** The delivery form is prefilled with each line's outstanding quantity and shows the limit ("up to 1,050 g").
4. **Errors in kitchen language.** Field errors use the ingredient name and unit ("Beef: 1,100 g is above the 1,050 g limit") and sit next to the field. State conflicts (409) appear as a notice and refresh the view, because the data on screen is out of date.
5. **Guiding empty states.** Every empty list says what to do next, with the action button ("No suppliers yet. Add one to start ordering.").
6. **Readable numbers and units.** Thousands separators. Grams and ml display as kg and L from 1,000 upward, with the exact base value on hover. Quantity inputs for g and ml have a unit switch (g/kg, ml/L) and always send integer base units (D-038; rules in ui.md). Pieces never convert. Negative values in danger colour with the word "Negative".
7. **Visible freshness.** Live views auto-refresh, values that changed highlight briefly, and "Updated N s ago" is always shown.
8. **Kitchen-ready.** Usable on a tablet (820 px), tap targets at least 44 px, keyboard navigable with a visible focus ring, WCAG AA contrast, reduced motion respected.
