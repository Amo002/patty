# Scope

## In

The six features in the brief, nothing more:

1. Ingredients (name, unit) and suppliers: create and list.
2. Menu items and their recipes.
3. Purchase orders with lines, and states draft, sent, received, closed. Only valid moves are allowed.
4. Deliveries against a purchase order, full or partial. Stock rises by what arrived. The order closes when everything has been received.
5. A POS sale endpoint. Stock falls according to the recipe.
6. Visibility: current stock per ingredient, and open purchase orders with what is outstanding. Always current.

Plus the delivery requirements of the brief: README, tests, AI usage notes and Git history.

Supporting work we chose to include, each small and justified in [decisions.md](decisions.md):

- A Postman collection with a `local` environment (the POS endpoint is designed to be called by another system).
- An optional Docker setup, so the app runs on a machine without PHP.
- GitHub Actions CI running style checks and tests.
- An audit trail (spatie/laravel-activitylog) and per-domain log channels (D-021, D-022).
- An "Incoming" quantity on the stock view, to serve the brief's goal of not running out (D-020).
- Opt-in browser tests, if time allows (D-026).

## Out (and staying out unless asked in the live session)

This section matters more than the one above. Every item here was considered and rejected on purpose.

| Out | Why |
|---|---|
| Authentication, users, login | The brief says no authentication is needed. A login screen is friction for the reviewer. |
| Roles and permissions (spatie/laravel-permission) | Follows from no users. Listed under "next steps". |
| File and image uploads (spatie/laravel-medialibrary) | Nothing in the brief has an image. |
| Multi-branch, stock transfers | One branch. |
| Prices, costing, valuation, journal postings (the financial pillar) | The brief is quantities only, and costing brings edge cases (cost of negative stock, of over-delivered excess) that would each need tests and defence. The ledger maps one-to-one onto postings. See architecture.md section 8 and D-018. |
| Multi-currency | One branch, one currency, and no money at all (D-018). |
| Unit conversion (buy in kg, consume in g) | One unit per ingredient, see Q-006. |
| Deployment, staging or production servers | The brief says no deployment. |
| Queues, events and listeners, scheduler, websockets | Synchronous transactions are simpler and correct at this size. The triggers that would change this are in D-017. |
| Server-side caching of stock | Stock must never be stale. It is derived on read. |
| SPA framework, npm, a JS build step | The reviewer must be able to run the app with PHP and Composer only. |
| Internationalisation | English only. |
| Emoji anywhere in UI, docs or commits | House style. |
