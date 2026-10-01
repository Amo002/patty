# Architecture

The big picture of Patty on one page. Details live in the linked docs.

## 1. The problem and whether we solve it

A one-branch burger restaurant wants to *"stop running out of ingredients and stop guessing what's in the storeroom"*, and to know *"at any moment how much of each ingredient they have and which orders are still open"* ([brief.md](brief.md)).

| Pain | How Patty answers it | Verdict |
|---|---|---|
| Guessing what's in the storeroom | Stock is derived from every delivery and sale. It is never stored, so it cannot drift from its history. | Solved |
| "At any moment" | No caching anywhere, `no-store` headers, auto-refresh, a visible "updated N s ago" | Solved |
| Deliveries don't match the order | Partial deliveries, 5% over-delivery tolerance, short-close | Solved |
| Every sale uses a recipe | Each sale explodes into one movement per recipe ingredient. POS retries are idempotent. | Solved |
| Which orders are still open | Open orders with ordered / received / outstanding per line | Solved |
| Orders can't reach a bad state | One transition map, guarded operations, every invalid move tested | Solved |
| Stop running out | On hand **plus Incoming** (outstanding on open orders) per ingredient, so the manager sees what is already on the way | Solved for "what's coming". Reorder levels are the next step. |

What software alone cannot solve: the number only matches the shelf if every event is recorded. Physical stock counts fix that drift. They are the natural next feature, and negative stock is the early-warning signal until then.

## 2. System context

```
 +------------------+        HTTP (fetch, JSON)        +-------------------------+
 | Manager          | -------------------------------> |                         |
 | (web UI, Blade + |   X-Patty-Channel: ui            |         Patty           |
 |  Alpine)         | <------------------------------- |   Laravel 13 / PHP 8.4  |      +-----------+
 +------------------+                                  |   /api/v1  (JSON API)   | ---> |  SQLite   |
                                                       |   /        (page shells)|      +-----------+
 +------------------+   POST /api/v1/sales             |                         |
 | POS system       | -------------------------------> |                         | ---> storage/logs/*.log
 |                  |   X-Patty-Channel: pos           |                         |
 +------------------+                                  +-------------------------+
```

The UI holds no business logic. Every page is a shell that reads and writes through the same API the POS uses.

## 3. Layers

```
Request
  -> Middleware        RequestContext (request id, channel), SecurityHeaders, NoStoreCache,
                       ForceJsonResponse (api), throttle:pos (sales)
  -> FormRequest       Shape and type validation: integers, exists, bounds      -> 422
  -> Controller        Thin. Calls ONE service method, returns via ApiResponse trait
  -> Service           Business rules, inside DB::transaction                   -> 409 / 422
       -> StockLedger  The ONLY writer of stock_movements
       -> Models       Eloquent, enum casts, derived quantities
       -> activity()   Audit entry, same transaction
       -> Log::channel Operational log line
  -> Database          FKs, unique indexes, append-only triggers on stock_movements
Response               { success, message, data | code, errors }  +  X-Request-Id
```

| Layer | Lives in | Owns |
|---|---|---|
| Middleware | `app/Http/Middleware` | Cross-cutting HTTP concerns |
| Validation | `app/Http/Requests` | Is the input well-formed? |
| Controllers | `app/Http/Controllers/Api/V1` | HTTP in and out, nothing else |
| Services | `app/Services` | Use cases and rules |
| Enums | `app/Enums` | Units, movement reasons, the PO transition map |
| Domain exceptions | `app/Exceptions/Domain` | Named rule violations, each with its status and code |
| Resources | `app/Http/Resources` | JSON shape of `data` |

## 4. Core flows

### Receive a delivery (PTY-8)
```
UI  POST /purchase-orders/{id}/deliveries  { lines: [{ purchase_order_line_id, quantity }] }
  ReceivingService::receive
    BEGIN TRANSACTION
      lock PO row; status must be sent | received          else 409 cannot_receive
      per line: received_so_far + qty <= max_receivable     else 422 over_delivery (nothing saved)
      insert delivery + delivery_lines
      per delivery line: StockLedger::record(+qty, delivery, line)
      sent -> received (first delivery)
      every line outstanding == 0  ->  received -> closed
      activity('delivery.recorded'), Log::channel('purchasing')
    COMMIT
  201 { success: true, data: purchase order with ordered/received/outstanding }
```

### POS sale (PTY-9)
```
POS POST /sales  { menu_item_id, quantity, pos_reference }
  SaleService::record
    pos_reference seen before?  ->  200, original sale, replayed: true, no stock moves
    BEGIN TRANSACTION
      menu item has a recipe                                else 422 menu_item_not_sellable
      insert sale
      per recipe line: StockLedger::record(-(line.qty x sale.qty), sale)
      any ingredient now negative -> Log::channel('stock')->warning
      activity('sale.recorded')
    COMMIT
  201 { success: true, data: sale, deductions, negatives }
```

### Purchase order states (PTY-7)
```
          send                first delivery            nothing outstanding (auto)
 draft ----------> sent ----------------------> received ----------------------------> closed
                                                        \--- short-close (manager) ---/
 Anything else: 409 invalid_transition. Defined once, in PurchaseOrderStatus.
```

## 5. Truth versus views

| Truth (rows that are only ever inserted) | View (computed on read) |
|---|---|
| `stock_movements` | on hand = sum of deltas per ingredient |
| `delivery_lines` | received, outstanding, over-received per PO line |
| `delivery_lines` of open POs | incoming per ingredient |
| `activity_log` | the history panel |

This is the bookkeeping principle: postings are the truth, balances are a view of them. It is also why most likely extensions are small. Waste, a stock take adjustment and supplier returns are each a new movement reason, not a new mechanism.

## 6. Cross-cutting concerns

| Concern | Approach | Decision |
|---|---|---|
| API responses | One envelope via the `ApiResponse` trait. Every error rendered in one place (`bootstrap/app.php`). | D-019 |
| Status codes | 200 / 201 / 401 (POS key) / 404 / 405 / **409 state forbids** / **422 input wrong** / 429 / 500. Full list in [api.md](api.md). | D-019, D-028 |
| Identifiers | ULIDs in URLs and payloads; integer ids never leave the server; human document numbers (PO, GRN, SALE) | D-031 |
| Versioning | `/api/v1`, V1 namespaces, `X-API-Version`, written policy for breaking changes | D-031 |
| Lists | Paginated everywhere; skeletons and lazy loading in the UI | D-032 |
| Audit trail | spatie/laravel-activitylog: field changes plus named business events, with channel, request id and IP. No fake users. | D-021 |
| Logging | Per domain: `stock`, `purchasing`, `pos`, `catalog`. `laravel.log` is errors only. Daily, 14 days, request id on every line. | D-022 |
| Freshness | No server cache, `no-store`, polling plus refetch on focus and back navigation | D-006 |
| Async | None. No events, queues or scheduler, and the triggers that would change that are written down. | D-017 |
| Money | None. The brief is quantities only. See section 8 for how finance would plug in. | D-018 |

## 7. Defence in depth

How "stock numbers that are always right" is protected, layer by layer (D-024):

1. **Input:** FormRequests accept integers only, check `exists`, and bound quantities (max 1,000,000 per line, 50 lines).
2. **Domain:** services and the single transition map reject what the current state forbids.
3. **Atomicity:** each use case is one transaction. A delivery or sale lands completely or not at all.
4. **Concurrency:** the PO row is locked while receiving. A unique `pos_reference` stops double-counted retries.
5. **Database:** foreign keys, unique indexes, and triggers that make `stock_movements` impossible to update or delete, even from a console or a bug.
6. **HTTP:** rate limit on the POS endpoint, security headers, `no-store`.
7. **Process:** tests that fail without the logic, CI on every PR, protected branches, a reviewer agent one tier above the builder, and the owner's line-by-line review.

## 8. How Patty joins the ERP (not built, by design)

- **Finance:** each movement maps to a journal posting. A receipt is Dr Inventory / Cr Goods Received Not Invoiced. A sale is Dr Cost of Sales / Cr Inventory, valued at weighted-average cost. Movements already carry the reference needed to post.
- **Procurement:** supplier invoices matched against PO lines and delivery lines (three-way match).
- **Multi-branch:** a `location_id` on movements. On hand becomes a sum per location, and transfers become a pair of movements.
- **Reorder:** a minimum level per ingredient. "Below minimum, nothing incoming" becomes a suggested PO.

## 9. Decisions and roadmap

- All decisions: [decisions.md](decisions.md) (D-001 onwards). Questions: [questions/](questions/).
- Contract and behaviour: [api.md](api.md), [validation.md](validation.md), [flows.md](flows.md), [ui.md](ui.md), [security.md](security.md).
- Phases and journal: [progress.md](progress.md).
- Tickets and releases: [tickets/BOARD.md](tickets/BOARD.md) and [workflow.md](workflow.md#releases).
