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

## Out (and staying out unless asked in the live session)

This section matters more than the one above. Every item here was considered and rejected on purpose.

| Out | Why |
|---|---|
| Authentication, users, login | The brief says no authentication is needed. A login screen is friction for the reviewer. |
| Roles and permissions (spatie/laravel-permission) | Follows from no users. Listed under "next steps". |
| File and image uploads (spatie/laravel-medialibrary) | Nothing in the brief has an image. |
| Multi-branch, stock transfers | One branch. |
| Multi-currency, prices and costing | The brief never mentions prices. Purchase order lines carry quantities only. |
| Accounting: journal entries, inventory valuation | Not asked. A natural extension, discussed in the README. |
| Unit conversion (buy in kg, consume in g) | One unit per ingredient, see Q-006. |
| Deployment, staging or production servers | The brief says no deployment. |
| Queues, events and listeners, websockets | Synchronous transactions are simpler and correct at this size. |
| Server-side caching of stock | Stock must never be stale. It is derived on read. |
| SPA framework, npm, a JS build step | The reviewer must be able to run the app with PHP and Composer only. |
| Internationalisation | English only. |
| Emoji anywhere in UI, docs or commits | House style. |
