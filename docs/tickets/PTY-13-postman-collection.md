# PTY-13 Postman collection

| Field | Value |
|---|---|
| Type | Task |
| Phase | 6 Release and submission |
| Status | Awaiting Mohamad |
| Weight | S |
| Builder | Opus 5.5 (orchestrator; agents at the usage limit) |
| Reviewer | Opus 5.5 |
| Branch | `PTY-13-postman-collection` |
| Release | v1.0.0 |
| Depends on | PTY-10 |

## Goal

Anyone, including a POS integrator, can exercise the whole API in Postman.

## Acceptance criteria

- [x] `docs/postman/patty.postman_collection.json`, with folders: Ingredients, Suppliers, Menu & Recipes, Purchase Orders, Deliveries, Sales (POS), Visibility
- [x] `docs/postman/local.postman_environment.json` with `base_url = http://127.0.0.1:8000/api/v1`
- [x] A "Scenario" folder that runs the brief end to end in order: create ingredients, recipe, PO, send, partial delivery, sale, check stock. Tests assert the numbers.
- [x] Example responses saved for the error cases: 409 `invalid_transition`, 409 `cannot_receive`, 422 `over_delivery`, 422 `validation_failed`, 429 `too_many_requests`
- [x] Requests send `X-Patty-Channel: api` (or `pos` for the Sales folder), so the audit trail shows where they came from

## Additions from D-035 to D-038

- [x] A "Demo data" folder: clear, seed, reset (E30 to E32), noting they are local only.
- [x] The scenario folder includes an under-tolerance completion (receive 960 of 1000) and a cap rejection.

## Builder notes

- **Generated, not hand-written.** A throwaway PHP script (not committed) wrote both JSON files, so every write request gets the same headers: `Accept`, `X-Patty-Channel` (`api`, or `pos` for Sales), and `Content-Type: application/json` even with no body. That header is required once PTY-21 is merged (S6). The Sales requests also send `X-POS-Key: {{pos_key}}`, which is empty by default.
- **Scenario** (21 requests, 47 assertions). It creates its own Beef, Bun and Cheese with a per-run suffix, so it runs on a seeded database and can be re-run. Steps:
  - a draft order;
  - receiving against the draft (409 `cannot_receive`) and sending twice (409 `invalid_transition`);
  - 960 of 1000 g beef, complete within the 5% under-tolerance;
  - over-delivery refused by percentage (buns) and by the 2,000 g cap (cheese ordered 50,000 g, so 5% would allow 52,500 g but the cap stops at 52,000 g);
  - the closing delivery, after which the order closes by itself;
  - 2 Classic Burgers and the till's replay;
  - final stock: beef 660 g, bun 8, cheese 49,960 g;
  - beef history with balances 660 then 960;
  - a 422 `validation_failed`.
- **Verified with newman** against a throwaway server on its own SQLite file: 47 of 47 assertions pass, and a second run on the same database passes too. `npx -y newman` was only used to run the checks; it is not a project dependency.
- **The saved error examples are real responses** captured from that run: `cannot_receive`, `invalid_transition`, `over_delivery`, `validation_failed`. The 429 is real too: 125 sales were fired at the throwaway server, and the 121st was refused, with `Retry-After: 10`.
- The reference folders hold one request per endpoint (E1 to E32) and reuse the ids the scenario saved. They are for trying one call at a time, not for running in order (for example, deleting a draft after the scenario has sent it answers 409, as it should).
