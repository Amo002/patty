# API contract (v1)

The single reference for every endpoint. Builders implement exactly this. Postman (PTY-13) and the UI tickets consume exactly this. Tickets link here instead of restating payloads. Validation rules per field: [validation.md](validation.md). End-to-end behaviour: [flows.md](flows.md).

## Conventions

| Topic | Rule |
|---|---|
| Base URL | `/api/v1` |
| Format | JSON in and out. `Accept: application/json` is forced on api routes. |
| Envelope | Success: `{ "success": true, "message": "...", "data": ..., "meta": {...} }`. Failure: `{ "success": false, "message": "...", "code": "...", "errors": {...} }` (D-019). |
| Identifiers | Every addressable record is identified by a **ULID** string (`"01JA8ZK3W5Y7Q2M4N6P8R0T2V4"`). Integer database ids never appear in a URL, a request or a response (D-031). Rows that are not addressable on their own (movements, activity entries) carry no id. |
| References in requests | `*_id` fields hold the ULID of the referenced record (`"supplier_id": "01JA..."`). |
| Document numbers | Human-readable and display-only: `PO-2026-0001`, `GRN-2026-0001`, `SALE-2026-000001`. Per type, per year, sequential. Never used for lookup. |
| Quantities | Integers in the ingredient's unit (`g`, `ml`, `piece`). Never decimals. |
| Time | UTC, ISO-8601 with `Z` (`"2026-10-02T11:30:00Z"`). The UI converts to the viewer's local timezone. |
| Pagination | Every collection takes `?page=` (default 1) and `?per_page=` (default 25, max 100, more gives 422). `meta.pagination = { page, per_page, total, last_page, has_more }`. |
| Headers in | `X-Request-Id` (optional, echoed back). `X-Patty-Channel: ui \| pos \| api` (default `api`, recorded in the audit trail). `X-POS-Key` (only if `POS_API_KEY` is configured, sales only). |
| Headers out | `X-Request-Id`, `X-API-Version: 1`, `Cache-Control: no-store, max-age=0`, security headers (D-024). |
| Freshness | Live views carry `meta.generated_at`. |

## Status codes and error codes

| Status | `code` | Meaning |
|---|---|---|
| 200 | | Read, or an action on an existing record (including a replayed sale) |
| 201 | | Created |
| 401 | `unauthorized` | POS key configured and missing or wrong |
| 404 | `not_found` | Unknown route or ULID (a numeric id is always 404) |
| 405 | `method_not_allowed` | Wrong HTTP verb |
| 409 | `invalid_transition` | The order's status does not allow this move |
| 409 | `order_not_editable` | Lines can only change while the order is draft |
| 409 | `cannot_receive` | Deliveries only against sent or partially received orders |
| 409 | `unit_locked` | The unit cannot change once stock has moved |
| 409 | `idempotency_conflict` | `pos_reference` already used with a different item or quantity |
| 409 | `demo_not_empty` | Demo seed refused because data already exists (use reset) |
| 422 | `validation_failed` | Input is malformed; `errors` holds `{ field: [messages] }` |
| 422 | `over_delivery` | The quantity would take the line above `max_receivable` (over % or absolute cap, D-035); `errors` names the line |
| 422 | `menu_item_not_sellable` | The menu item has no recipe |
| 429 | `too_many_requests` | Rate limit on sales exceeded |
| 500 | `server_error` | Unexpected. Generic message, never details. |

## Versioning policy

- The version is in the path (`/api/v1`). Routes: `routes/api/v1.php`. Controllers, requests and resources live under a `V1` namespace. Services and the domain are shared, not versioned.
- Additive changes (a new field, a new endpoint, a new optional parameter) stay in v1. Clients must ignore unknown fields.
- Breaking changes (removing or renaming a field, changing a meaning or a status code) create `/api/v2` alongside v1.
- A version being retired first returns `Deprecation: true` and `Sunset: <date>` headers for at least one release.

---

## Endpoint index

| # | Method | Path | Purpose | Success | Errors | Ticket |
|---|---|---|---|---|---|---|
| E1 | GET | `/health` | Liveness and envelope check | 200 | | PTY-16 |
| E2 | GET | `/ingredients` | List ingredients with stock | 200 | 422 | PTY-5 |
| E3 | POST | `/ingredients` | Create ingredient | 201 | 422 | PTY-5 |
| E4 | GET | `/ingredients/{ingredient}` | One ingredient | 200 | 404 | PTY-5 |
| E5 | PATCH | `/ingredients/{ingredient}` | Rename, or change unit | 200 | 404, 409 `unit_locked`, 422 | PTY-5 |
| E6 | GET | `/ingredients/{ingredient}/movements` | Stock history with running balance | 200 | 404, 422 | PTY-10 |
| E7 | GET | `/suppliers` | List suppliers | 200 | 422 | PTY-5 |
| E8 | POST | `/suppliers` | Create supplier | 201 | 422 | PTY-5 |
| E9 | GET | `/suppliers/{supplier}` | One supplier | 200 | 404 | PTY-5 |
| E10 | PATCH | `/suppliers/{supplier}` | Update supplier | 200 | 404, 422 | PTY-5 |
| E11 | GET | `/menu-items` | List menu items with recipes | 200 | 422 | PTY-6 |
| E12 | POST | `/menu-items` | Create menu item (optionally with recipe) | 201 | 422 | PTY-6 |
| E13 | GET | `/menu-items/{menuItem}` | One menu item | 200 | 404 | PTY-6 |
| E14 | PATCH | `/menu-items/{menuItem}` | Rename | 200 | 404, 422 | PTY-6 |
| E15 | PUT | `/menu-items/{menuItem}/recipe` | Replace recipe | 200 | 404, 422 | PTY-6 |
| E16 | GET | `/purchase-orders` | List POs, `?status=draft\|sent\|received\|closed\|open` (`open` = sent or received) | 200 | 422 | PTY-7, PTY-10 |
| E17 | POST | `/purchase-orders` | Create draft PO | 201 | 422 | PTY-7 |
| E18 | GET | `/purchase-orders/{purchaseOrder}` | One PO with line progress | 200 | 404 | PTY-7 |
| E19 | PUT | `/purchase-orders/{purchaseOrder}/lines` | Replace lines (draft only) | 200 | 404, 409 `order_not_editable`, 422 | PTY-7 |
| E20 | POST | `/purchase-orders/{purchaseOrder}/send` | draft to sent | 200 | 404, 409 `invalid_transition` | PTY-7 |
| E21 | POST | `/purchase-orders/{purchaseOrder}/close` | Short-close (received to closed) | 200 | 404, 409 `invalid_transition` | PTY-7 |
| E22 | DELETE | `/purchase-orders/{purchaseOrder}` | Delete a draft | 200 | 404, 409 `invalid_transition` | PTY-7 |
| E23 | POST | `/purchase-orders/{purchaseOrder}/deliveries` | Record a delivery | 201 | 404, 409 `cannot_receive`, 422 `over_delivery`, 422 | PTY-8 |
| E24 | GET | `/purchase-orders/{purchaseOrder}/deliveries` | Deliveries of a PO | 200 | 404, 422 | PTY-8 |
| E25 | POST | `/sales` | POS sale event | 201 (new), 200 (replay) | 401, 409 `idempotency_conflict`, 422 `menu_item_not_sellable`, 422, 429 | PTY-9 |
| E26 | GET | `/sales` | Recent sales | 200 | 422 | PTY-9 |
| E27 | GET | `/stock` | Stock per ingredient: on hand, incoming, negative | 200 | 422 | PTY-10 |
| E28 | GET | `/dashboard` | KPI counts | 200 | | PTY-10 |
| E29 | GET | `/activity` | Audit trail, `?subject_type=&subject_id=` | 200 | 422 | PTY-10 |
| E30 | POST | `/demo/reset` | Clear, then seed. **Local only.** | 200 `{ counts }` | 404 outside local | PTY-22 |
| E31 | POST | `/demo/clear` | Empty the system completely (`migrate:fresh`, D-037). **Local only.** | 200 | 404 outside local | PTY-22 |
| E32 | POST | `/demo/seed` | Load realistic demo data into an empty system. **Local only.** | 200 `{ counts }` | 404 outside local, 409 `demo_not_empty` | PTY-22 |

---

## Resource shapes

### Ingredient
```json
{
  "id": "01JA8ZK3W5Y7Q2M4N6P8R0T2V4",
  "name": "Beef",
  "unit": "g",
  "unit_label": "grams",
  "on_hand": 700,
  "incoming": 400,
  "is_negative": false,
  "unit_locked": true,
  "tolerance": { "over_bps": 500, "under_bps": 500, "over_cap": 2000, "source": "default" },
  "image_url": "http://127.0.0.1:8000/images/seed/ingredients/beef.webp",
  "created_at": "2026-10-01T08:00:00Z",
  "updated_at": "2026-10-01T08:00:00Z"
}
```

### Supplier
```json
{ "id": "01JA...", "name": "Al-Mashreq Meats", "email": "orders@mashreq.example", "phone": "+962 6 555 0101", "created_at": "..." }
```

### Menu item
```json
{
  "id": "01JA...",
  "name": "Classic Burger",
  "is_sellable": true,
  "image_url": "http://127.0.0.1:8000/images/seed/menu/classic-burger.webp",
  "recipe": [
    { "ingredient": { "id": "01JA...", "name": "Beef", "unit": "g" }, "quantity": 150 },
    { "ingredient": { "id": "01JA...", "name": "Bun", "unit": "piece" }, "quantity": 1 },
    { "ingredient": { "id": "01JA...", "name": "Cheese", "unit": "g" }, "quantity": 20 }
  ],
  "created_at": "..."
}
```

### Purchase order
```json
{
  "id": "01JA...",
  "number": "PO-2026-0002",
  "status": "received",
  "status_label": "Partially received",
  "short_closed": false,
  "supplier": { "id": "01JA...", "name": "Al-Mashreq Meats" },
  "lines": [
    {
      "id": "01JA...",
      "ingredient": { "id": "01JA...", "name": "Beef", "unit": "g" },
      "quantity_ordered": 1000,
      "quantity_received": 600,
      "quantity_outstanding": 400,
      "quantity_over_received": 0,
      "quantity_under_delivered": 0,
      "is_complete": false,
      "max_receivable": 1050,
      "min_to_complete": 950,
      "tolerance": { "over_bps": 500, "under_bps": 500, "over_cap": 2000 }
    }
  ],
  "progress_percent": 60,
  "allowed_actions": ["receive", "short_close"],
  "sent_at": "...", "closed_at": null, "created_at": "..."
}
```
- `allowed_actions` is a subset of `edit_lines`, `send`, `delete`, `receive`, `short_close`, derived from the status. The UI shows only these buttons.
- A line is **complete** at `min_to_complete` (under-tolerance), and then `quantity_outstanding` is 0 and any shortfall is `quantity_under_delivered`. `tolerance` is the snapshot taken when the line was created (D-035).
- `progress_percent` is the **average of each line's own completion**, `min(received, ordered) / ordered`, floored. Quantities of different units (g and pieces) are never added together.

### Delivery
```json
{
  "id": "01JA...", "number": "GRN-2026-0003", "received_at": "...", "note": "Driver: Sami",
  "lines": [ { "purchase_order_line_id": "01JA...", "ingredient": { "id": "...", "name": "Beef", "unit": "g" }, "quantity": 600 } ]
}
```

### Sale
```json
{
  "id": "01JA...", "number": "SALE-2026-000124",
  "menu_item": { "id": "01JA...", "name": "Classic Burger" },
  "quantity": 2, "pos_reference": "till-1:000873", "sold_at": "...",
  "replayed": false,
  "deductions": [
    { "ingredient": { "id": "...", "name": "Beef", "unit": "g" }, "quantity": 300, "on_hand_after": 700, "is_negative": false },
    { "ingredient": { "id": "...", "name": "Cheese", "unit": "g" }, "quantity": 40, "on_hand_after": -10, "is_negative": true }
  ]
}
```

### Stock row (E27)
```json
{ "ingredient": { "id": "...", "name": "Cheese", "unit": "g" }, "on_hand": -10, "incoming": 0, "is_negative": true }
```

### Movement (E6), with no id
```json
{
  "reason": "delivery", "reason_label": "Delivery",
  "quantity_delta": 600, "balance_after": 600,
  "reference": { "type": "delivery", "number": "GRN-2026-0003", "label": "GRN-2026-0003 for PO-2026-0002" },
  "occurred_at": "..."
}
```
`balance_after` is a running sum computed in SQL with a window function (`SUM(...) OVER (ORDER BY occurred_at, id)`), so it is correct on every page.

### Dashboard (E28)
```json
{ "ingredients_count": 6, "negative_count": 1, "open_orders_count": 2, "outstanding_lines_count": 3 }
```

### Activity entry (E29), with no id
```json
{
  "event": "purchase_order.sent", "description": "PO-2026-0003 sent to Golden Bakery",
  "subject": { "type": "purchase_order", "id": "01JA...", "label": "PO-2026-0003" },
  "channel": "ui", "request_id": "9f1c...", "changes": { "status": ["draft", "sent"] },
  "created_at": "..."
}
```

---

## Request bodies (field rules in [validation.md](validation.md))

| Endpoint | Body |
|---|---|
| E3 POST ingredients | `{ "name": "Beef", "unit": "g", "over_tolerance_bps"?: 500, "under_tolerance_bps"?: 500, "over_tolerance_cap"?: 2000 }` |
| E5 PATCH ingredient | any subset of the E3 fields. Tolerance fields accept `null` (back to the unit default). |
| E8 POST suppliers | `{ "name": "...", "email"?: "...", "phone"?: "..." }` |
| E10 PATCH supplier | any subset of the above |
| E12 POST menu-items | `{ "name": "Classic Burger", "recipe"?: [ { "ingredient_id": "01JA...", "quantity": 150 } ] }` |
| E14 PATCH menu item | `{ "name": "..." }` |
| E15 PUT recipe | `{ "lines": [ { "ingredient_id": "01JA...", "quantity": 150 } ] }` |
| E17 POST purchase-orders | `{ "supplier_id": "01JA...", "lines": [ { "ingredient_id": "01JA...", "quantity_ordered": 1000 } ] }` |
| E19 PUT lines | `{ "lines": [ { "ingredient_id": "01JA...", "quantity_ordered": 1000 } ] }` |
| E20, E21, E22 | no body |
| E23 POST deliveries | `{ "lines": [ { "purchase_order_line_id": "01JA...", "quantity": 600 } ], "received_at"?: "...", "note"?: "..." }` |
| E25 POST sales | `{ "menu_item_id": "01JA...", "quantity": 2, "pos_reference"?: "till-1:000873", "sold_at"?: "..." }` |

## Example: a failure
```http
POST /api/v1/purchase-orders/01JA.../deliveries
X-Patty-Channel: ui

{ "lines": [ { "purchase_order_line_id": "01JA...", "quantity": 500 } ] }
```
```json
HTTP/1.1 422
X-Request-Id: 9f1c0b7e-...
{
  "success": false,
  "message": "Beef: receiving 500 g would bring the total to 1,100 g, above the 1,050 g limit.",
  "code": "over_delivery",
  "errors": { "lines.0.quantity": ["Beef: 1,100 g is above the 1,050 g limit."] }
}
```
