# PTY-13 Postman collection

| Field | Value |
|---|---|
| Type | Task |
| Status | To Do |
| Weight | S |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-13-postman-collection` |
| Release | v1.0.0 |
| Depends on | PTY-10 |

## Goal

Anyone, including a POS integrator, can exercise the whole API in Postman.

## Acceptance criteria

- [ ] `docs/postman/patty.postman_collection.json`, with folders: Ingredients, Suppliers, Menu & Recipes, Purchase Orders, Deliveries, Sales (POS), Visibility
- [ ] `docs/postman/local.postman_environment.json` with `base_url = http://127.0.0.1:8000/api/v1`
- [ ] A "Scenario" folder that runs the brief end to end in order: create ingredients, recipe, PO, send, partial delivery, sale, check stock. Tests assert the numbers.
- [ ] Example responses saved for the 422 cases (invalid transition, over-delivery)
