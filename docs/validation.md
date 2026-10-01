# Validation rules

The single source of truth for what input is accepted. Every rule here has a test (T21). Every FormRequest lives in `App\Http\Requests\V1` and implements its section. Business rules that depend on stored state (status, stock, tolerance) are **not** in FormRequests. They live in services and fail with 409 or 422 domain codes (see [api.md](api.md#status-codes-and-error-codes)).

## Global rules

| # | Rule | Why |
|---|---|---|
| G1 | Services receive only `$request->validated()`. Unknown fields are ignored and never reach a model. Every model declares `$fillable`. | Mass assignment is impossible |
| G2 | Strings are trimmed, and empty strings become `null` (Laravel's global `TrimStrings` and `ConvertEmptyStringsToNull`) | `" Beef "` and `"Beef"` are the same name |
| G3 | Quantities: `integer` plus `min`/`max`. Rejected values: `"1.5"`, `1.5`, `"abc"`, `"1e3"`, `-1`, `0`, `null`, `true`, `[]`. | Integers only; no float ever enters stock (NFR-1) |
| G4 | References are ULID strings: `string`, `ulid`, `exists:<table>,ulid`. A numeric id fails `ulid`. The FormRequest resolves ULIDs to models in `passedValidation()`, so services receive models, not strings. | No internal ids accepted (D-031) |
| G5 | Arrays of lines: `array`, `min:1`, `max:50`. Each line's reference uses `distinct`. | Bounded payloads; no duplicate lines |
| G6 | Names are unique **case-insensitively**, enforced twice: by `Rule::unique` (ignoring the record itself on PATCH) and by the database (a unique index on a `COLLATE NOCASE` column). | "Beef" and "beef" can never coexist, even past a race |
| G7 | Every message uses the word the manager sees. `attributes()` maps `lines.*.quantity` to "quantity". Line errors are prefixed with the line number and ingredient name ("Line 2 (Beef): quantity must be at least 1"). | Kitchen-language errors (design.md rule 4) |
| G8 | Lists: `page` is `integer`, `min:1`. `per_page` is `integer`, `min:1`, `max:100`, and above 100 gives 422 (explicit rather than silently clamped). | Predictable paging |
| G9 | Dates: `date` (ISO-8601). Stored in UTC. | One time standard |
| G10 | PATCH uses `sometimes` with the same rules as create, so a field that is sent must be valid, and a field that is absent is untouched. | Partial updates |

## Per endpoint

### Ingredients (E3 POST, E5 PATCH): `StoreIngredientRequest`, `UpdateIngredientRequest`
| Field | Rules | Example failure |
|---|---|---|
| name | required (POST), `string`, `min:2`, `max:100`, unique (G6) | "An ingredient called Beef already exists." |
| unit | required (POST), `Rule::enum(Unit::class)` (`g`, `ml`, `piece`) | "Unit must be one of g, ml, piece." |
| unit change when movements exist | service: `UnitLocked` | 409 `unit_locked`: "Beef already has stock history in g, so its unit can't change." |
| over_tolerance_bps | `nullable`, `integer`, `min:0`, `max:10000` (0% to 100%, basis points) | "Over-delivery tolerance must be between 0% and 100%." |
| under_tolerance_bps | `nullable`, `integer`, `min:0`, `max:10000` | |
| over_tolerance_cap | `nullable`, `integer`, `min:0`, `max:1000000`, in the ingredient unit | |

### Suppliers (E8, E10): `StoreSupplierRequest`, `UpdateSupplierRequest`
| Field | Rules |
|---|---|
| name | required (POST), `string`, `min:2`, `max:120`, unique (G6) |
| email | `nullable`, `email:rfc`, `max:255` |
| phone | `nullable`, `string`, `max:30`, `regex:/^[0-9+\-\s()]+$/` |

### Menu items (E12, E14, E15): `StoreMenuItemRequest`, `UpdateMenuItemRequest`, `ReplaceRecipeRequest`
| Field | Rules |
|---|---|
| name | required (POST), `string`, `min:2`, `max:100`, unique (G6) |
| recipe / lines | E12: `sometimes`, array (G5). E15: required, array (G5). |
| *.ingredient_id | required, ULID (G4) on `ingredients`, `distinct` |
| *.quantity | required, integer (G3), `min:1`, `max:1000000` |

### Purchase orders (E17, E19): `StorePurchaseOrderRequest`, `ReplacePurchaseOrderLinesRequest`
| Field | Rules |
|---|---|
| supplier_id | E17: required, ULID (G4) on `suppliers` |
| lines | required, array (G5) |
| lines.*.ingredient_id | required, ULID (G4) on `ingredients`, `distinct` |
| lines.*.quantity_ordered | required, integer (G3), `min:1`, `max:1000000` |
| order status (E19) | service: `OrderNotEditable`, 409 |

E20 send, E21 close and E22 delete take no body. The status rules give 409 `invalid_transition`. Sending an order with no lines is impossible, because E17 requires at least one line and E19 cannot remove them all (`min:1`).

### Deliveries (E23): `StoreDeliveryRequest`
| Field | Rules |
|---|---|
| lines | required, array (G5) |
| lines.*.purchase_order_line_id | required, `ulid`, `distinct`, and **exists on this order**: `Rule::exists('purchase_order_lines', 'ulid')->where('purchase_order_id', <route order id>)` |
| lines.*.quantity | required, integer (G3), `min:1`, `max:1000000` |
| received_at | `nullable`, `date`, `before_or_equal:now`, and **not before the order's `sent_at`** (checked in `after()`) |
| note | `nullable`, `string`, `max:255` |
| order status | service: `CannotReceive`, 409 |
| tolerance | service: `OverDelivery`, 422 `over_delivery`. The total received would exceed the line's `max_receivable` = ordered + min(over %, over cap), using the line's **snapshot** tolerance (D-035). |

### Sales (E25): `StoreSaleRequest`
| Field | Rules |
|---|---|
| menu_item_id | required, ULID (G4) on `menu_items` |
| quantity | required, integer (G3), `min:1`, `max:1000` per sale event |
| pos_reference | `nullable`, `string`, `max:64`, `regex:/^[A-Za-z0-9._:-]+$/` |
| sold_at | `nullable`, `date`, no more than 5 minutes in the future (clock skew between POS and server) |
| X-POS-Key | middleware: when `POS_API_KEY` is set, it must match (`hash_equals`), else 401 |
| recipe exists | service: `MenuItemNotSellable`, 422 |
| reference reuse | service. The same payload is a replay (200). A different `menu_item_id` or `quantity` gives 409 `idempotency_conflict`. |

### Queries
| Endpoint | Param | Rules |
|---|---|---|
| all lists | page, per_page | G8 |
| E16 purchase orders | status | `nullable`, in `draft`, `sent`, `received`, `closed`, `open` |
| E29 activity | subject_type | `nullable`, in `purchase_order`, `ingredient`, `supplier`, `menu_item`, `sale` |
| E29 activity | subject_id | `nullable`, `ulid`, `required_with:subject_type` |

### Demo data (E30 to E32)
No body. Registered only in the local environment. E32 returns 409 `demo_not_empty` if any data exists.

## Bounds and overflow

The largest possible single stock movement is 1,000 (max sale quantity) x 1,000,000 (max recipe quantity) = 10^9. The largest delivery line is 1,000,000. Sums over thousands of movements stay far below the 64-bit integer limit (about 9.2 x 10^18). No overflow is possible within these bounds.
