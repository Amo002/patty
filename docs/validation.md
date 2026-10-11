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
| name | required (POST), `string`, `min:2`, `max:100`, unique (G6) | "An ingredient with this name already exists." |
| unit | required (POST), `Rule::enum(Unit::class)` (`g`, `ml`, `piece`) | "Unit must be one of g, ml, piece." |
| unit change when the ingredient is used | service: `UnitLocked` | 409 `unit_locked`: "Beef is already used in recipes, so its unit (g) can't change." Used means stock movements, recipe lines or PO lines (D-044). Resending the same unit is allowed. |
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
| lines | required, array (G5), `min:1`, `max:50` |
| lines.*.menu_item_id | required, string, ULID (G4, lowercased first), exists on `menu_items` |
| lines.*.quantity | required, `integer:strict` (G3), `min:1`, `max:1000` |
| pos_reference | `nullable`, `string`, `max:64`, `regex:/^[A-Za-z0-9._:-]+$/` |
| sold_at | `nullable`, `date`, no more than 5 minutes in the future (clock skew between POS and server) |
| X-POS-Key | middleware: when `POS_API_KEY` is set, it must match (`hash_equals`), else 401 |
| duplicates | **merged**, not rejected: the same menu item on two lines becomes one line with the quantities added, before anything else. After merging, a line above 1,000 is a 422 on that line. |
| old body | `{ menu_item_id, quantity }` is no longer accepted: 422 on `lines` (required) |
| recipe exists | service: `MenuItemNotSellable`, 422 `menu_item_not_sellable` if **any** item has no recipe. The whole ticket is refused (no sale, no lines, no movements, no SALE number used). |
| reference reuse | service. The same merged lines, in any order, are a replay (200). Any other lines (a changed quantity, an added or removed item) give 409 `idempotency_conflict`. |

Errors are keyed `lines`, `lines.N.menu_item_id` and `lines.N.quantity` (N is 0-based in the key, 1-based in the message). Messages:

| Rule | Message |
|---|---|
| lines required, empty | Add at least one menu item to the sale. |
| lines max | A sale can hold at most 50 lines. |
| menu_item_id missing | Line N: choose the menu item that was sold. |
| menu_item_id not a string | Line N: the menu item must be an id. |
| menu_item_id not a valid ULID | Line N: the menu item is not a valid id. |
| menu_item_id unknown | Line N: that menu item does not exist. |
| quantity missing, not a whole number, below 1 or above 1,000 | Line N: the quantity must be a whole number from 1 to 1,000. |
| merged duplicates above 1,000 | Line N: {item name} appears more than once and adds up to more than 1,000. |

### Refunds (E33): `StoreSaleReturnRequest`
| Field | Rules |
|---|---|
| lines | required, array (G5), `min:1`, `max:50` |
| lines.*.menu_item_id | required, string, ULID (G4, lowercased first), exists on `menu_items` |
| lines.*.quantity | required, `integer:strict` (G3), `min:1`, `max:1000` |
| restock | required, `boolean:strict`: only JSON `true` or `false`. `"true"`, `1`, `0` and `null` are rejected. There is no default on the API (D-049). |
| reason | required, `string`, 1 to 200 characters, no control characters (the same rule as names) |
| pos_reference | the same rules as on sales: `nullable`, `string`, `max:64`, `regex:/^[A-Za-z0-9._:-]+\z/` |
| X-POS-Key | middleware, as for sales |
| duplicates | merged before anything else, as for sales |
| item on the sale | service: `ItemNotOnSale`, 422 `item_not_on_sale`, when a menu item is not a line of this sale |
| quantity left | service: `OverRefund`, 422 `over_refund`. Per line, left = line quantity - earlier refunds of that line. Checked for every line, inside the transaction with the sale locked, before anything is written. All breaches are reported at once. |
| reference reuse | service. The same sale, the same merged lines (any order) and the same `restock` are a replay (200). Anything else is 409 `idempotency_conflict`. The `reason` is not compared: a replay returns the original refund with its original reason. |
| both errors at once | If a request has an item not on the sale and also an over-refund, only `item_not_on_sale` is reported. |

Validation errors are keyed `lines`, `lines.N.menu_item_id`, `lines.N.quantity`, `restock` and `reason` (N is 0-based in the key, 1-based in the message). Messages:

| Rule | Message |
|---|---|
| lines required, empty | Add at least one item to refund. |
| lines max | A refund can hold at most 50 lines. |
| menu_item_id missing or invalid | Line N: choose the menu item to refund. |
| menu_item_id unknown | Line N: that menu item does not exist. |
| quantity missing, not a whole number, below 1 or above 1,000 | Line N: the quantity must be a whole number from 1 to 1,000. |
| restock missing or not a boolean | Choose whether the ingredients go back to stock. |
| reason missing | Give a reason for the refund. |
| reason above 200 characters | The reason must be at most 200 characters. |
| reason with line breaks or control characters | The reason cannot contain line breaks or other control characters. |

Domain errors (not FormRequest rules):

| Code | Key | Message |
|---|---|---|
| `item_not_on_sale` | `lines.N.menu_item_id` | Line N: Fries is not on SALE-2026-000125. |
| `over_refund` | `lines.N.quantity` | Line N: only 1 Fries left to refund on SALE-2026-000125. |

For merged duplicates the key and the row number are those of the item's first row. The `over_refund` response message is the first breach's text without the "Line N: " prefix.

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

The largest possible single stock movement is 1,000 (max quantity on one sale line) x 1,000,000 (max recipe quantity) = 10^9. The largest delivery line is 1,000,000. Sums over thousands of movements stay far below the 64-bit integer limit (about 9.2 x 10^18). No overflow is possible within these bounds.
