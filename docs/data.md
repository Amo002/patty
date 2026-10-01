# Data model

The rule behind the whole model: **events are stored, balances are derived.** On-hand stock and outstanding order quantities are never stored. They are computed from the rows that caused them, the same way an account balance is computed from its ledger postings.

## Diagram

```
suppliers 1---* purchase_orders 1---* purchase_order_lines *---1 ingredients
                      |                       |                     |
                      1                       1                     |
                      *                       *                     |
                 deliveries 1---* delivery_lines                    |
                                                                    |
menu_items 1---* recipe_lines *-------------------------------------+
     |                                                              |
     1                                                              |
     *                                                              |
   sales                                                            |
                                                                    |
stock_movements *---------------------------------------------------+
   (reference -> delivery_lines | sales | future: adjustments)
```

## Tables

All tables have `id` and `created_at`/`updated_at` unless noted. All quantities are **unsigned integers in the ingredient's unit**, except `stock_movements.quantity_delta`, which is signed.

### ingredients
| Column | Type | Notes |
|---|---|---|
| name | string, unique | |
| unit | string | enum `Unit`: `g`, `ml`, `piece`. Locked once movements exist. |

### suppliers
| Column | Type | Notes |
|---|---|---|
| name | string, unique | |
| email | string, nullable | |
| phone | string, nullable | |

### menu_items
| Column | Type | Notes |
|---|---|---|
| name | string, unique | |

### recipe_lines
| Column | Type | Notes |
|---|---|---|
| menu_item_id | fk, cascade | |
| ingredient_id | fk, restrict | |
| quantity | unsigned int, > 0 | Per one unit of the menu item. |
| | unique(menu_item_id, ingredient_id) | |

### purchase_orders
| Column | Type | Notes |
|---|---|---|
| supplier_id | fk, restrict | |
| status | string | enum `PurchaseOrderStatus`: `draft`, `sent`, `received`, `closed` |
| sent_at | timestamp, nullable | Set on draft to sent. |
| closed_at | timestamp, nullable | Set on received to closed. |
| short_closed | boolean, default false | True when closed manually with quantity still outstanding (Q-004). |

### purchase_order_lines
| Column | Type | Notes |
|---|---|---|
| purchase_order_id | fk, cascade | |
| ingredient_id | fk, restrict | |
| quantity_ordered | unsigned int, > 0 | |
| | unique(purchase_order_id, ingredient_id) | |

### deliveries
| Column | Type | Notes |
|---|---|---|
| purchase_order_id | fk, restrict | |
| received_at | timestamp | When the goods arrived. Defaults to now. |
| note | string, nullable | For example, a delivery note number. |

### delivery_lines
| Column | Type | Notes |
|---|---|---|
| delivery_id | fk, cascade | |
| purchase_order_line_id | fk, restrict | |
| quantity_received | unsigned int, > 0 | |

### sales
| Column | Type | Notes |
|---|---|---|
| menu_item_id | fk, restrict | |
| quantity | unsigned int, > 0 | |
| pos_reference | string, nullable, unique | Idempotency key from the POS (Q-005). |
| sold_at | timestamp | Defaults to now. |

### stock_movements (append-only)
| Column | Type | Notes |
|---|---|---|
| ingredient_id | fk, restrict | |
| quantity_delta | signed int, != 0 | Positive in, negative out. |
| reason | string | enum `MovementReason`: `delivery`, `sale` (later: `waste`, `adjustment`, ...) |
| reference_type / reference_id | morph | The row that caused it: a `delivery_line` or a `sale`. |
| occurred_at | timestamp | Business time (when the goods arrived or the sale happened). |
| created_at | timestamp | System time. No `updated_at`: rows are never updated. |
| | index(ingredient_id) | On-hand is a sum over this index. |

### activity_log (package table, audit only)
Created by spatie/laravel-activitylog. One row per field change or named business event (`purchase_order.sent`, `delivery.recorded`, `sale.replayed`, ...). `properties` holds `channel`, `request_id`, `ip`, and before/after values. `causer` is always null, because there are no users. **Not part of the stock truth:** nothing is ever computed from it. Stock movements are not duplicated here (D-021).

### Database-level guards
- SQLite triggers `stock_movements_no_update` and `stock_movements_no_delete` abort any `UPDATE` or `DELETE` on `stock_movements` (D-024).
- Foreign keys are enforced (Laravel's SQLite default).

## Derived values

```
on_hand(ingredient)     = SUM(stock_movements.quantity_delta) WHERE ingredient_id = ?
received(po_line)       = SUM(delivery_lines.quantity_received) WHERE purchase_order_line_id = ?
outstanding(po_line)    = max(0, quantity_ordered - received(po_line))
over_received(po_line)  = max(0, received(po_line) - quantity_ordered)
max_receivable(po_line) = intdiv(quantity_ordered * (100 + tolerance_percent), 100)   -- integers, rounds down
po fully received       = every line has outstanding == 0
incoming(ingredient)    = SUM(outstanding(po_line)) over lines of that ingredient on POs in sent | received
```

## Invariants (each one has a test)

1. The only code that inserts into `stock_movements` is `StockLedger`. Nothing updates or deletes a movement.
2. Each delivery line produces exactly one movement of `+quantity_received`.
3. Each sale produces exactly one movement per recipe line, of `-(recipe quantity x sale quantity)`.
4. `received(po_line) <= max_receivable(po_line)` always (over-delivery tolerated up to 5%, beyond that rejected, Q-002).
5. `purchase_orders.status` changes only through `PurchaseOrderStatus::transitionTo()` rules.
6. A delivery or sale and its movements are written in one transaction, or not at all.
