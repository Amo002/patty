# Patty

[![CI](https://github.com/Amo002/patty/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/Amo002/patty/actions/workflows/ci.yml)

Inventory and purchasing for a burger restaurant with one branch. A manager defines ingredients, suppliers and recipes, orders from suppliers, records deliveries (all or part of an order), and sees stock fall as the POS reports sales. Everything is done from the web UI, which uses the same API a POS or any other client would.

The README follows the brief's list: how to run it and the tests, the data model, the decisions, how AI was used, and what is next. More detail follows those five sections.

## How to run it and how to run the tests

### Run it

Requires PHP 8.3 or newer (8.4 is what CI uses), Composer, and the `pdo_sqlite` extension. No Node, no database server.

```sh
git clone https://github.com/Amo002/patty.git
cd patty
composer setup        # install, .env, app key, SQLite file, migrate and seed the demo restaurant
php artisan serve     # open http://127.0.0.1:8000
```

`composer setup` is for the first run: it rebuilds the database. After that, `php artisan serve` is enough. To start again from the demo data, use **Reset demo** in the "Restaurant manager" menu in the header, or `php artisan patty:demo:reset`.

There is no login, by design (the brief asks for none). The demo data includes an order still waiting on part of its delivery and cheese already below zero, so every screen has something to show from the first visit.

### Run the tests

```sh
php artisan test
```

The suite runs against an in-memory SQLite database, so it never touches your data. CI runs Pint, the tests and `composer audit` on every pull request.

The tests that matter most:

| What | Where |
|---|---|
| A sale deducts exactly the recipe (2 Classic Burgers: beef -300 g, buns -2, cheese -40 g) | `tests/Feature/Sales` |
| A partial delivery raises stock by what arrived and leaves the order open; the last one closes it | `tests/Feature/Purchasing/ReceivingTest.php` |
| Every invalid status move is refused, and so is receiving against a draft | `tests/Feature/Purchasing`, `tests/Unit/PurchaseOrderStatusTest.php` |
| Over-delivery tolerances: over %, under %, absolute cap | `tests/Unit/ToleranceTest.php`, `ReceivingTest.php` |
| A sale below zero is recorded and flagged | `tests/Feature/Sales` |
| A full day of interleaved deliveries and sales ends on exactly the right stock | `tests/Feature/Visibility/DayAtPattyTest.php` |
| The database itself refuses to change or delete stock history | `tests/Feature/Schema/ConstraintsTest.php` |

During the build every rule was **mutation-checked**: the rule was broken on purpose, and a test had to fail. Tests that kept passing were rewritten or removed. Details are in [docs/AI_LOG.md](docs/AI_LOG.md).

### Run with Docker (optional)

For a machine without PHP. Requires Docker with Compose.

```sh
docker compose up --build     # first start builds, migrates and seeds; then open http://127.0.0.1:8000
docker compose run --rm app php artisan test
docker compose down -v        # stop and delete the database volume (the next start re-seeds)
```

The SQLite file lives on the named volume `patty-data`, so data survives restarts. The port is published on `127.0.0.1` only.

### Try the API

`docs/postman/` holds a Postman collection and a local environment. The **Scenario** folder runs the brief end to end and checks every number:
- a partial delivery;
- over-delivery refused by percentage and by cap;
- the order closing itself;
- a POS sale and its replay;
- the final stock.

It also runs from the command line:

```sh
npx newman run docs/postman/patty.postman_collection.json -e docs/postman/local.postman_environment.json --folder Scenario
```

The full contract is [docs/api.md](docs/api.md).

## Data model

```
suppliers ──< purchase_orders ──< purchase_order_lines >── ingredients ──< recipe_lines >── menu_items
                    │                      │                    │                                │
                    └──< deliveries ──< delivery_lines          │                              sales
                                           │                    │                                │
                                           └────────────> stock_movements <──────────────────────┘
```

- **`stock_movements` is the heart of it.** It is an append-only list of signed changes: `+960` for a delivery line, `-300` for a sale. Each row records the delivery line or sale that caused it. **On hand is never stored**: it is the sum of an ingredient's movements.
- **Outstanding is never stored either.** For each order line it is what was ordered minus what has arrived. **Incoming** (shown next to on hand) is the sum of outstanding over open orders.
- **Quantities are whole numbers** in the ingredient's unit: grams, millilitres or pieces. No floats anywhere, so no rounding drift. The UI shows and accepts kg and L, and converts with exact arithmetic.
- **Ids:**
  - public ids are ULIDs, and integer ids never leave the server;
  - documents get readable numbers (`PO-2026-0002`, `GRN-2026-0003`, `SALE-2026-000124`).

Full table by table: [docs/data.md](docs/data.md).

## Decisions where the brief was unclear, and why

Each one was raised as a question ([docs/questions/](docs/questions/)), answered, and recorded with its reasons in [docs/decisions.md](docs/decisions.md).

### Stock is a ledger, not a number (D-004)

Every change is a new movement, and on hand is their sum. A stored balance can drift from its history; a derived one cannot. Every number on screen traces back to the delivery or sale that caused it. Corrections are new movements, never edits. It is the double-entry idea: postings are the truth, and balances are a view of them.

The rule is enforced in two places:
- **in code:** only one service writes movements;
- **in the database:** SQLite triggers refuse UPDATE and DELETE on `stock_movements`.

### A sale that takes stock below zero is accepted and flagged (D-010)

The POS reports a sale that has already happened: the customer has the burger. Refusing it would not undo the sale; it would only remove it from the records. Negative stock is real information: an unrecorded delivery, a miscount, or loss. So the sale is recorded and the ingredient shows **Negative** in red, where the manager will see it.

### Partial deliveries, and what closes an order (D-012, D-013)

The order moves `draft → sent → received → closed`.
- **Received** means partly received. The first delivery moves an order there.
- **Closing is automatic:** the delivery that completes the last line closes the order in the same transaction.
- **Short-close:** if a supplier will never send the rest, the manager can close a received order by hand. It is marked short-closed, and the missing quantity stays visible.

Allowed moves live in one transition map. Every other move, and every action the current status forbids (receiving against a draft, editing a sent order), is refused with **409**. Bad input is **422**.

### Over- and under-delivery tolerances (D-035)

Real deliveries are rarely exact. SAP and Dynamics handle this per line, and so does Patty:
- **Over:** a line can receive up to 5% more than ordered, but never more than 2 kg or 2 L in absolute terms. Pieces have no absolute cap. The percentage rounds down, so 10 buns allow no extra and 300 buns allow 15.
- **Under:** a line counts as complete from 5% under. Beef in the demo is set to 2%, because it is the costly line.
- **Per ingredient:** the defaults can be overridden for each ingredient.
- **Snapshot:** each order line keeps the tolerances it was ordered with, so changing an ingredient later never changes an order already sent.

### One unit per ingredient (D-015, D-044)

An ingredient is counted in g, ml or pieces, and orders, recipes and stock all use that unit. The unit is locked once the ingredient is used anywhere, because changing g to ml would silently reinterpret every quantity already recorded.

Real purchasing often buys in a different unit than it consumes (a 20 L drum, used in ml). That needs a conversion factor per ingredient, which is listed below as a next step.

### Duplicate POS calls (D-014, D-029)

A till that loses its connection resends the sale. An optional `pos_reference` makes the endpoint idempotent:
- **Replay:** the same reference with the same item and quantity returns the original sale (200) and moves no stock.
- **Conflict:** the same reference with a different item or quantity is a 409.

Retries are the most likely real-world way for stock to go wrong, and arithmetic tests alone would never catch them.

### What is edited and what is not (D-016)

- **Drafts:** an order's lines and supplier can change only while it is a draft, and only drafts can be deleted.
- **Recipes:** can change at any time, and affect future sales only.
- **History:** deliveries, sales and movements are never edited or deleted.

### Freshness (D-006, D-020)

Nothing about stock is cached. Every screen reads it fresh, refreshes every 10 seconds and on focus, and shows when it was last updated. "Is this up to date?" is never a guess.

### Deliberately not built

- **No events, queues or scheduler (D-017).** Every write is one synchronous transaction, which is easy to reason about and test. The decision record lists what would change that.
- **No prices or accounting (D-018).** The brief asks for quantities. The ledger already maps one-to-one onto journal postings; see "How Patty joins the ERP" below.
- **No login, roles or uploads (D-001).** The brief asks for none.

## How I used AI

Claude Code (Opus 5.5) was the orchestrator. Sonnet agents built each ticket in its own git worktree, and Opus agents reviewed every backend ticket before its pull request. I answered every unclear point in the brief, set the working rules, and reviewed and merged every pull request. The full record, written as the work happened, is [docs/AI_LOG.md](docs/AI_LOG.md).

### The main prompts

Summarised, not verbatim. Each one, with what came back, is in the AI log.

1. **Planning:** "Read the brief and plan a professional process: docs first, Jira-style tickets, a builder and a reviewer on a higher model for every ticket, my review on every pull request, every commit mine with no AI co-author, no emoji." The AI pushed back on four of my ideas because they went against the brief: roles and auth, image uploads, dev/staging/prod branches, and a Vite build. I accepted all four.
2. **The unclear parts of the brief:** "Raise every question the brief doesn't answer as a file with options and a recommendation." This produced Q-001 to Q-016. I overrode the AI on over-delivery: it recommended rejecting any excess, and I chose a tolerance.
3. **Architecture:** "Do we need events, queues or a scheduler? Isn't an ERP supposed to have a financial side?" The answer was no to both, with the reasons recorded (D-017, D-018). The ledger maps onto journal postings later. I also asked for one API response envelope, an audit trail, log channels per domain, and layers of protection.
4. **Tolerances:** "Delivery tolerance like SAP and Microsoft Dynamics, both." The AI first explained how each system actually behaves, so that "both" became a concrete rule: over %, under %, and an absolute cap, whichever is stricter, copied onto each order line.
5. **Building:** "Build ticket PTY-N", with a brief listing the files the builder may touch, and "commit after every working piece". Each ticket then went to a reviewer: "Review against the ticket and the docs; break each rule on purpose and check a test fails."
6. **Security:** "Review against the threat model in docs/security.md", run on the finished code.

### What worked

- **A reviewer one tier above the builder, with mutation checks.** It caught real bugs that a green test suite missed:
  - sale times sent with an offset (`+03:00`) were stored three hours wrong;
  - stock log lines were written for deliveries that were rolled back;
  - Laravel's `integer` rule accepted `true` as 1;
  - an ingredient's history never showed more than 25 movements;
  - a running balance would have been wrong for backdated entries.
- **Questions in files, before code.** Every rule in "Decisions" above traces back to a question and a recorded answer.
- **Checking against the running system, not the reasoning.** This found the CORS hole and three Docker build failures (below).

### What I had to fix

- **Tests that could not fail:** Laravel's example `true === true`, a photo-size test that looped over nothing, and a test claiming to prove something it did not. Each was removed or rewritten.
- **A security fix that did nothing on its own.** The first fix for cross-site requests relied on the browser asking the server first, and Laravel's default CORS setting said yes to every site. Testing it against the running server showed this; CORS is now off.
- **Docker written without being run.** It failed three ways on its first real build: a database extension rebuilt for no reason, an unused image library, and read-only folders from the Windows checkout.
- **The AI putting words in my mouth.** It wrote a rationale for an over-delivery decision as if it were mine (D-011). It was corrected and I wrote my own: follow SAP and Dynamics practice.
- **Guessing before diagnosing.** On the first day Laravel would not boot. The AI tried two wrong fixes before finding the real cause, a Windows read-only folder flag that PHP honours.
- **Process slips:** a test run in the wrong folder, a file written into the wrong worktree, a factual error in the first README draft, and a builder that tried a guessed credential header (blocked by the safety system).

## What I'd do next with more time

1. **Reorder levels and suggested orders.** A minimum per ingredient. "Below minimum with nothing incoming" becomes a draft order for the usual supplier. The data for it (on hand, incoming) already exists.
2. **Stock take and waste.** Enter a physical count, and the system posts the difference as an adjustment movement and shows the variance. Waste is one more movement reason. Both are additive because of the ledger.
3. **Purchase units.** Buy in cases or drums and consume in g or ml, with a conversion factor per ingredient and supplier.
4. **Login and roles.** A manager can order and receive, kitchen staff can only see stock, and the POS gets its own token instead of the optional shared key.

Also on the list: optimistic locking on draft edits, static analysis with Larastan, and browser tests ([PTY-17](docs/tickets/PTY-17-browser-tests.md), deferred).

## More detail

### How Patty joins the ERP

Not built, but the seams are in place:

- **Finance:** each movement becomes a journal posting:
  - a delivery: Dr Inventory, Cr Goods Received Not Invoiced;
  - a sale: Dr Cost of Sales, Cr Inventory, at weighted-average cost.

  Movements already carry the reference a posting needs.
- **Procurement:** supplier invoices matched against order lines and delivery lines (three-way match).
- **More branches:** a `location_id` on movements. On hand becomes a sum per location, and a transfer becomes a pair of movements.
- **New stock events** are a new movement reason, not a new mechanism:
  - waste;
  - a stock take posting its variance;
  - a return to a supplier.

### Logs versus audit trail

| | Audit trail | Logs |
|---|---|---|
| For | The manager: who did what, when, and from where | The developer: what the system did, and what broke |
| Where | The `activity_log` table, shown on the **Activity** page and on each purchase order | `storage/logs/stock.log`, `purchasing.log`, `pos.log`, `catalog.log`; `laravel.log` holds errors only |
| Holds | Business events (`purchase_order.sent`, `delivery.recorded`, `sale.recorded`) with field changes, the channel (UI, POS or API) and the request id | One line per business step, with the request id, kept 14 days |

The request id ties the two together. It appears in every response header, every error message the UI shows, every audit entry and every log line. "What happened to PO-2026-0002 at 14:02" is one search.

### Security

There is no login, because the brief asks for none. That risk is contained rather than ignored; the full threat model is in [docs/security.md](docs/security.md).

- `php artisan serve` and the Docker setup listen on `127.0.0.1` only.
- **Cross-site requests are blocked.** Every API write must be sent as JSON, and CORS allows no other origin. A malicious page open in the same browser therefore cannot post to the local API.
- Stock history is append-only in code and in the database.
- Public ids are ULIDs. Every query uses bindings. The UI renders API data as text only. Errors show a message and a request id, never a trace or SQL.
- The POS endpoint can require a shared key (`POS_API_KEY`) and is rate limited. The demo data endpoints exist only when `APP_ENV=local`.

The security review (PTY-21, 2026-10-02, Opus 5.5) found 8 issues: 4 fixed, 2 accepted with written reasons, and 2 false positives. See [the ticket](docs/tickets/PTY-21-security-review.md). A second, independent pass on a stronger model was planned and dropped for lack of credits (D-047). `composer audit` runs in CI on every pull request.

### Project docs

| Doc | What |
|---|---|
| [docs/README.md](docs/README.md) | Start here: reading order for all docs |
| [docs/architecture.md](docs/architecture.md) | The big picture: layers, flows, protection, how it joins an ERP |
| [docs/brief.md](docs/brief.md) | The problem and what success looks like |
| [docs/requirements.md](docs/requirements.md) | Functional and non-functional requirements with acceptance criteria |
| [docs/api.md](docs/api.md) | Every endpoint, payload and error code |
| [docs/data.md](docs/data.md) | Data model and invariants |
| [docs/decisions.md](docs/decisions.md) | Decisions taken, alternatives rejected, and why |
| [docs/questions/](docs/questions/) | Where the brief was unclear |
| [docs/security.md](docs/security.md) | Threat model |
| [docs/tickets/BOARD.md](docs/tickets/BOARD.md) | Ticket board |
| [docs/progress.md](docs/progress.md) | Phase journal |
| [docs/AI_LOG.md](docs/AI_LOG.md) | How AI was used, and where it got things wrong |
