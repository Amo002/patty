# UI

What the manager sees. **One web app**: one site, one sidebar, every page linked. Every page is a Blade shell that loads its data from the API ([api.md](api.md)). Visual design lives in [design.md](design.md).

> **Status:** the page inventory and behaviour rules below are decided. Final visual screen specs (layout, components per screen) come from **phase 2b, Frontend design**, and are added here after Mohamad approves the designs. The UI ticket split (Q-012) is decided then too.

## Shell

```
+--------------------------------------------------------------+
| PATTY                         Restaurant manager (no login)  |
+--------------+-----------------------------------------------+
| Dashboard    |  <page title>                Updated 3 s ago  |
| Ingredients  |                                               |
| Suppliers    |  <page content>                               |
| Menu&Recipes |                                               |
| Purchase Ord.|                                               |
| POS Simulator|                                               |
| Activity     |                                               |
+--------------+-----------------------------------------------+
```

- **Identity chip** (top right): "Restaurant manager", with the tooltip "No login, by design. See the README." (D-027)
- **First-visit banner**: "Single-branch demo. No login needed. Start with the guided tour." Dismissible, remembered in localStorage. In local env it also carries "Reset demo data".
- Below 820 px the sidebar collapses into a top menu button.

## Page inventory

| Page | URL | Data (endpoints) | What the manager does |
|---|---|---|---|
| Dashboard | `/` | E28, E27, E16 `?status=open` | Sees KPIs, stock (on hand, incoming, negative) and open POs with progress. Guided "Try it" card. |
| Ingredients | `/ingredients` | E2, E3, E5, E6 | Adds, renames, sees stock, opens the history drawer |
| Suppliers | `/suppliers` | E7, E8, E10 | Adds and edits suppliers |
| Menu & Recipes | `/menu` | E11, E12, E14, E15 | Adds menu items and edits recipes |
| Purchase Orders | `/purchase-orders`, `/purchase-orders/{ulid}`, `/purchase-orders/new` | E16 to E24, E29 | Lists, drafts, edits, sends, receives, short-closes; sees the order's activity |
| POS Simulator | `/pos` | E11, E25, E26, E33 | Builds a ticket of one or more menu items and sells it; resends the last ticket to show retry protection (D-048); refunds items of a recent sale, with or without putting the ingredients back (D-049) |
| Activity | `/activity` | E29 | Sees every recorded event with its channel |

URLs use ULIDs, never database ids (D-031).

## Behaviour rules (decided)

| # | Rule | Source |
|---|---|---|
| U1 | **Skeleton loading:** every list, card and KPI shows placeholders shaped like the real content until its data arrives. Shimmer animation, static under reduced motion. No layout shift when data lands. | D-032 |
| U2 | **Lazy loading:** lists fetch 25 at a time. The next page loads automatically when the user scrolls near the end (IntersectionObserver), with a "Load more" button as the keyboard fallback. Below-the-fold dashboard panels load when scrolled into view. | D-032 |
| U3 | **Freshness:** live views poll every 10 s while visible, refetch on focus and on `pageshow`, keep scroll position, flash changed values, and show "Updated N s ago". | D-006 |
| U4 | **States:** every data region has four designed states: loading (skeleton), empty (guiding text and action), error (message, retry button, request id), loaded. | design.md |
| U5 | **Actions:** only the actions in a PO's `allowed_actions` are shown. Buttons disable with a spinner while their request is in flight. | design.md rule 1 |
| U6 | **Confirmations** with plain summaries before: send PO, record delivery, short-close, delete draft, reset demo. | design.md rule 2 |
| U7 | **Errors:** 422 inline next to the field; 409 as a notice plus an automatic refresh; 401, 429 and 500 as a notice with the request id. | D-019 |
| U8 | **Time** shown in the viewer's machine timezone (`Intl.DateTimeFormat`), with relative time ("5 min ago") where useful and the exact time on hover. | D-030 |
| U9 | **Numbers and units:** thousands separators; g and ml scale to kg and L at 1,000 and above (exact base value on hover); quantity inputs have a g/kg or ml/L switch and send integer base units; pieces never convert; negative shown in danger colour with the word "Negative". Rules in the table below. | D-038 |
| U10 | **Guided "Try it" card** (Dashboard): five steps linking to screens, ticking themselves off from real data. Dismissible. | D-027 |
| U11 | Every request sends `X-Patty-Channel: ui` (the POS Simulator sends `pos`), so the audit trail knows where actions came from. | D-021 |
| U12 | API data is rendered with `x-text` or `textContent` only. **Never `x-html` or `innerHTML`.** | security.md |

## POS Simulator (PTY-29)

A till, not a form. The page builds a ticket and sends it as one sale (E25, D-048).

- **Tiles:** one tap-to-add tile per menu item (E11). Tapping adds the item to the ticket, or adds one more if it is already there.
- **Ticket:** one row per item with a stepper (quantity 1 to 1,000) and a remove button. Duplicates cannot appear, because a repeated tap raises the quantity. An empty ticket cannot be sold.
- **Per-ingredient preview:** before selling, the ticket shows what it will take per ingredient (recipe quantity x line quantity, summed across lines). It is a preview only: the server decides, and D-010 still allows stock to go below zero.
- **Sell:** sends `lines` and a `pos_reference`. On 201 the ticket clears (only after a sale sent from the ticket with Sell, never after a resend) and the result lists one row per ingredient (quantity taken, on hand after, negative highlighted). Items with no recipe come back as 422 `menu_item_not_sellable` and the whole ticket is refused.
- **Line errors:** 422 errors keyed `lines.N.*` from Sell appear on the matching ticket row.
- **In flight:** all ticket and resend controls are disabled while a sale is being sent, so a double tap cannot send twice.
- **Retry buttons:** "Resend last ticket" sends the same lines and reference again (200 replay, nothing moves). "Resend with one more of the first item" sends the same reference with changed lines (409 `idempotency_conflict`, nothing moves). Resend results and errors (replay, 409, 429) show as a notice, not on a ticket row.
- **Recent sales (E26):** each sale shows its items and total units. From PTY-30 it also shows a "Fries x1 refunded" pill per refunded line (from `refunded`) and a Refund button.

## Refunds in the POS Simulator (PTY-30)

A refund is a panel opened from a Recent sales row (E33, D-049). The sale is never edited.

- **Refund button:** one per row in Recent sales. When nothing is left to refund on the sale (`refunded_units` equals `units`), it reads "Refunded" and is disabled.
- **Pills:** each line with `refunded` above 0 shows a pill such as "Fries x1 refunded".
- **Panel steps:**
  1. **Items:** one stepper per sale line, from 0 up to what is left (`quantity - refunded`). Lines with nothing left are shown as refunded and cannot be raised.
  2. **Was the food made?** Two choices: "Yes, it was made" (`restock: false`, the ingredients are gone) and "No, it was voided before cooking" (`restock: true`, the ingredients go back). **"Yes, it was made" is preselected.**
  3. **Reason:** a select with Customer complaint, Wrong order, Cancelled before cooking and Long wait.
  4. **Refund:** disabled until at least one unit is chosen. It is busy while the request is in flight, so a double tap cannot send twice.
- **Preview:** under the choice, the panel says what will happen to stock. For "made": nothing changes. For "voided": the ingredients that go back, per ingredient (the sale line's own amounts for the chosen quantities, `-(movement) / quantity x chosen`). It is a preview only: the server decides.
- **Success:** a notice "Refund recorded." and Recent sales reloads, so the pills and the Refund button update. A replay shows "Refund already recorded. No stock moved."
- **Errors:** 422 `over_refund` and `item_not_on_sale` appear on the matching panel row (keys `lines.N.*`). 409 `idempotency_conflict`, 429 and 401 show as a notice, as for sales (U7).

## Unit conversion rules (public/js/units.js, D-038)

Storage and API are always integer base units. These rules exist only in the UI.

| Case | Input or value | Result |
|---|---|---|
| Display, small | 750 g | `750 g` |
| Display, scaled | 2400 g | `2.4 kg` (hover: `2,400 g`) |
| Display, scaled | 52000 g | `52 kg` |
| Display, precise | 1005 g | `1.005 kg` |
| Display, negative | -20 g | `-20 g` |
| Display, negative scaled | -1500 ml | `-1.5 L` |
| Display, pieces | 2400 piece | `2,400 pcs` (never scaled) |
| Input kg | `2.4` with kg | sends `2400` |
| Input kg | `1.005` with kg | sends `1005` (string parsing, not float multiplication) |
| Input kg, too precise | `1.0005` with kg | refused before sending: "Enter at most 3 decimals in kg" |
| Input g | `2.5` with g | refused before sending: "Grams must be a whole number" |
| Input L | `0.75` with L | sends `750` |
| Error re-expressed | server: 1100 g above 1050 g limit, user typed kg | "Beef: 1.1 kg is above the 1.05 kg limit" |

Defaults for the input switch: PO lines and deliveries start in kg and L; recipe lines start in g and ml.
