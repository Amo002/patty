# Brief (BRD)

## The problem

A burger restaurant with one branch keeps running out of ingredients and does not know what is actually in its storeroom.

It buys ingredients from a few suppliers. Deliveries often do not match the order: sometimes only part of it arrives. Every sale on the point-of-sale (POS) system uses up ingredients according to a recipe. For example, one Classic Burger uses 150 g beef, 1 bun and 20 g cheese. Nothing connects these three facts today.

## Who has the problem

- **The owner / manager.** Decides what to order and when. Needs to know, at any moment, how much of each ingredient is on hand and which orders are still open.
- **The POS.** Already records every sale but has nowhere to send it that affects stock.

## What they do today without it

- Stock is counted by eye or on paper, and the count is out of date as soon as service starts.
- Orders are tracked in the supplier's WhatsApp thread or in someone's memory. When half a delivery arrives, nobody records which half.
- They run out mid-service, or over-order to be safe and throw stock away.

## What success looks like

1. The manager opens one screen and sees the current quantity of every ingredient, and **trusts it**. The number reflects every delivery and every sale already recorded, without refreshing or guessing.
2. The manager sees every open purchase order and exactly **what is still outstanding** on each line.
3. A partial delivery is recorded in under a minute. Stock rises by what actually arrived, and the order stays open for the rest.
4. A sale reported by the POS lowers stock by exactly what its recipe says.
5. An order can never reach an impossible state (for example, received before it was sent, or edited after it was sent).

## Constraints

- One branch, one currency, no users or logins (see [scope.md](scope.md)).
- Built in roughly 6 to 8 hours of work. A smaller solution that works and is well tested beats a larger one with holes.

Source: the official build challenge brief. If anything in `docs/` conflicts with it, the brief wins.
