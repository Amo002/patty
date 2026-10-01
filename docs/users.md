# Users and their tasks

Two actors. One is a person, one is a system.

## 1. Restaurant manager (web UI)

Uses the web UI only. Never calls the API directly.

| # | Task | Feature |
|---|---|---|
| M1 | Add an ingredient with its unit (g, ml, piece) and see the list | FR-1 |
| M2 | Add a supplier and see the list | FR-1 |
| M3 | Define a menu item and the ingredients and quantities it uses | FR-2 |
| M4 | Draft a purchase order to a supplier with one or more ingredient lines, and fix it while still a draft | FR-3 |
| M5 | Send the order to the supplier (draft to sent) | FR-3 |
| M6 | Record what physically arrived, even if it is only part of the order | FR-4 |
| M7 | See current stock of every ingredient, and spot anything negative | FR-6 |
| M8 | See every open order and what is still outstanding on each line | FR-6 |
| M9 | Simulate a POS sale from the UI, to demonstrate stock falling | FR-5 |

## 2. Point-of-sale system (API client)

Calls one endpoint, once per sale (or per line of a ticket).

| # | Task | Feature |
|---|---|---|
| P1 | Report that N units of a menu item were sold | FR-5 |
| P2 | Retry safely after a network failure without the sale being counted twice | FR-5, Q-005 |

The POS reports facts that already happened in the real world: the customer already has the burger. That shapes how errors are handled (see Q-001).
