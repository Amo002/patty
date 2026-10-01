# Requirements

Each requirement has acceptance criteria (AC). A ticket is done only when the ACs it covers are proven by a test or by the manual QA checklist in [testing.md](testing.md).

Open questions are referenced as Q-NNN (see [questions/](questions/)). Until a question is closed, its recommended answer is assumed.

## Functional

### FR-1 Ingredients and suppliers

- AC1. A manager can create an ingredient with a name and a unit from a fixed list: `g`, `ml`, `piece`.
- AC2. Ingredient names are unique (case-insensitive). A duplicate returns 422 with a message naming the field.
- AC3. A manager can list ingredients, each showing its current on-hand quantity (see FR-6).
- AC4. A manager can create a supplier with a name (unique) and optional contact email and phone.
- AC5. A manager can list suppliers.
- AC6. An ingredient's unit cannot be changed once any stock movement exists for it. Changing it would silently reinterpret history.

### FR-2 Recipes

- AC1. A manager can create a menu item with a unique name.
- AC2. A manager can set its recipe: one or more lines of (ingredient, quantity > 0, an integer in the ingredient's unit).
- AC3. An ingredient appears at most once per recipe.
- AC4. A recipe can be edited. Edits affect future sales only, and past movements are never rewritten (Q-007).
- AC5. A menu item with no recipe lines cannot be sold (422).

### FR-3 Purchase orders

- AC1. A manager can create a purchase order for a supplier with one or more lines of (ingredient, quantity ordered > 0). It starts in `draft`.
- AC2. An ingredient appears at most once per order.
- AC3. Lines can be added, changed or removed only while the order is `draft`.
- AC4. Allowed transitions are defined in exactly one place in the code:
  - `draft -> sent` (manager action);
  - `sent -> received` (first delivery recorded);
  - `received -> closed` (automatic when nothing is outstanding, or manual short-close per Q-004).
- AC5. Every other transition is rejected with 422 and a message naming the current and the attempted state. This includes `draft -> received`, `draft -> closed`, `sent -> draft`, anything out of `closed`, and repeating the same state.
- AC6. A `draft` order with no lines cannot be sent.

### FR-4 Receiving deliveries

- AC1. A manager can record a delivery against an order that is `sent` or `received`, with one or more lines of (order line, quantity received > 0).
- AC2. Recording a delivery against a `draft` or `closed` order is rejected with 422.
- AC3. For every delivery line, stock of that ingredient rises by exactly the quantity received.
- AC4. Outstanding per line = ordered - sum(received). It is derived, never stored.
- AC5. A quantity greater than what is outstanding on that line is rejected with 422, and nothing from that delivery is saved (Q-002).
- AC6. The first delivery moves the order from `sent` to `received`.
- AC7. When every line has zero outstanding, the order moves to `closed` in the same transaction.
- AC8. A delivery is all-or-nothing: if any line fails validation, no stock moves.

### FR-5 Sales from the POS

- AC1. `POST /api/v1/sales` accepts `{ menu_item_id, quantity, pos_reference? }`.
- AC2. Stock of each recipe ingredient falls by `recipe quantity x sale quantity`. Example: 2 Classic Burgers lower beef by 300 g, bun by 2 and cheese by 40 g.
- AC3. A sale that takes an ingredient below zero is accepted and recorded, and the ingredient shows as negative (Q-001).
- AC4. If `pos_reference` is sent and was already recorded, the original sale is returned with 200 and stock does not move again (Q-005).
- AC5. Unknown menu item or quantity < 1 returns 422.
- AC6. A sale is all-or-nothing across its ingredients.

### FR-6 Visibility

- AC1. A stock view lists every ingredient with on-hand = sum of its stock movements, shown in its unit.
- AC2. Negative on-hand is visibly flagged.
- AC3. An open-orders view lists every order in `sent` or `received`, with each line's ordered, received and outstanding quantities.
- AC4. Both views reflect a delivery or sale immediately. They refresh on their own while open, show when they were last updated, and are never served from a browser or server cache.
- AC5. A stock history view per ingredient lists the movements that make up its balance (reason, delta, reference, time).

## Non-functional

- NFR-1 Correctness. Quantities are integers in the ingredient's unit. No floats anywhere in stock arithmetic.
- NFR-2 Integrity. `stock_movements` is append-only, and nothing updates or deletes a movement. Every stock change happens in a database transaction together with the event that caused it.
- NFR-3 Errors. Validation and domain errors return 422 JSON: `{ message, errors? }`. Unknown resources return 404 JSON. The UI shows these messages to the user.
- NFR-4 Runnable. A clean clone runs with `composer setup && php artisan serve`. Tests run with `php artisan test`.
- NFR-5 UI. Every feature above is reachable from the web UI without touching the API. It is usable with the keyboard, and motion respects `prefers-reduced-motion`.
- NFR-6 API. Versioned under `/api/v1`, JSON only, documented by the Postman collection.
