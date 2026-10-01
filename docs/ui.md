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
| POS Simulator | `/pos` | E11, E25, E26 | Sells N of a menu item; resends the last sale to show retry protection |
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
| U9 | **Numbers:** thousands separators, unit beside the number, kg/L hint for large g/ml values, negative shown in danger colour with the word "Negative". | design.md rule 6 |
| U10 | **Guided "Try it" card** (Dashboard): five steps linking to screens, ticking themselves off from real data. Dismissible. | D-027 |
| U11 | Every request sends `X-Patty-Channel: ui` (the POS Simulator sends `pos`), so the audit trail knows where actions came from. | D-021 |
| U12 | API data is rendered with `x-text` or `textContent` only. **Never `x-html` or `innerHTML`.** | security.md |
