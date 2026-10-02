# Q-012 How is the UI work cut into tickets, and what is PTY-17's scope?

Status: closed (2026-10-01)

## Context

The UI is one app with seven pages. The question is only how the work is cut for review: one ticket (PTY-12), or several (for example: Dashboard; Ingredients, Suppliers and Menu; Purchase Orders; POS Simulator and Activity).

## Recommendation

Decide after the screen designs are approved, when the size of each page is known.

## Answer

Decided at design approval (D-043), proposed by the orchestrator so the two builders can work in parallel (D-040). Mohamad can override in review.

- **PTY-12** Dashboard and Activity (the live views).
- **PTY-18** Ingredients, Suppliers, Menu and Recipes (catalogue pages).
- **PTY-19** Purchase Orders with the Receive dialog, and the POS Simulator (the transaction pages).

**PTY-17 (browser tests):** deferred. It is the first item in the cut order, and with about 8 hours left it is not planned. It is listed as a next step.
