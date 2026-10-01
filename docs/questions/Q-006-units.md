# Q-006 How are units handled?

Status: closed (2026-10-01)
Blocks: PTY-3

## Context

The brief gives "150 g of beef, 1 bun and 20 g of cheese", so ingredients have different units. Real purchasing often buys in a different unit than the kitchen consumes (kg cases, used in g; 20 L drums, used in ml).

## Options

**A. One unit per ingredient (`g`, `ml`, `piece`). Every quantity (recipe, PO line, delivery, stock) is an integer in that unit. No conversion.**
- Pro: no conversion bugs, integers only, no floats.
- Con: the manager orders "10000 g" instead of "10 kg".

**B. A purchase unit and a conversion factor per ingredient.**
- Con: real complexity (rounding, display, two units on every screen) for no requirement in the brief.

## Recommendation

**A.** Quantities are unsigned integers in the base unit. Use grams rather than kilograms, and ml rather than litres, so integers are always precise enough. A unit cannot change once stock has moved. The README notes purchase-unit conversion as a natural extension.

## Answer

**A, as recommended.** One unit per ingredient (g, ml, piece), unsigned integer quantities, no conversion; unit locked once stock has moved. Recorded as D-015.
