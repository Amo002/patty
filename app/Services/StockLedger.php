<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Models\Ingredient;
use App\Models\StockMovement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The only door through which stock changes, and the only definition of on-hand.
 *
 * D-004: nothing stores a balance. Every change is an appended signed movement
 * and on-hand is the sum of them, so the number can never drift from its history.
 * No other class may create StockMovement rows.
 */
class StockLedger
{
    /**
     * Append one signed movement to the ledger.
     *
     * Positive delta is stock in, negative is stock out. Callers run this inside
     * their own DB::transaction together with their audit entry, so the movement
     * and the document that caused it commit or roll back together.
     *
     * D-010: a movement that takes on-hand below zero is still recorded. The
     * physical event already happened, so refusing it would delete information;
     * it is flagged with a warning on the `stock` channel instead.
     *
     * @param  Model  $reference  the row that caused it (a DeliveryLine or a Sale)
     * @param  CarbonInterface|null  $occurredAt  business time; defaults to now
     *
     * @throws InvalidArgumentException when $delta is 0 (a no-op movement is noise in the audit trail)
     */
    public function record(
        Ingredient $ingredient,
        int $delta,
        MovementReason $reason,
        Model $reference,
        ?CarbonInterface $occurredAt = null,
    ): StockMovement {
        if ($delta === 0) {
            throw new InvalidArgumentException('A stock movement must change quantity: delta cannot be 0.');
        }

        $movement = StockMovement::create([
            'ingredient_id' => $ingredient->getKey(),
            'quantity_delta' => $delta,
            'reason' => $reason,
            // getMorphClass() gives the short alias from the morph map, not the class name.
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
            'occurred_at' => $occurredAt ?? now(),
        ]);

        $context = [
            'ingredient' => $ingredient->ulid,
            'ingredient_name' => $ingredient->name,
            'delta' => $delta,
            'reason' => $reason->value,
            'reference_type' => $reference->getMorphClass(),
            // Not every referenced model is addressable (delivery lines have no ulid), so fall back to the key.
            'reference' => $reference->ulid ?? $reference->getKey(),
        ];

        // Read after the insert, inside the same transaction, so it includes this movement.
        $onHand = $this->onHand($ingredient);

        // Logged only once the caller's transaction commits: a rollback removes the movement, and the
        // log must not claim a movement that no longer exists. Runs immediately outside a transaction.
        DB::afterCommit(function () use ($context, $onHand) {
            Log::channel('stock')->info('Stock movement recorded', $context);

            if ($onHand < 0) {
                Log::channel('stock')->warning('Stock is below zero', $context + ['on_hand' => $onHand]);
            }
        });

        return $movement;
    }

    /**
     * Current stock of one ingredient: SUM(quantity_delta), or 0 with no movements.
     *
     * Always computed from the movements, never cached (D-004), so it reflects
     * a delivery or sale the instant its transaction commits.
     */
    public function onHand(Ingredient $ingredient): int
    {
        // SUM over zero rows is NULL, which (int) turns into 0.
        return (int) StockMovement::query()
            ->where('ingredient_id', $ingredient->getKey())
            ->sum('quantity_delta');
    }

    /**
     * On-hand for every ingredient that has movements, in ONE grouped query.
     *
     * Keyed by ingredient id. An ingredient with no movements is absent, so
     * callers read it with `$map->get($id, 0)`. Same arithmetic as onHand(),
     * which is what lets a list screen avoid one query per ingredient.
     *
     * @return Collection<int, int>
     */
    public function onHandForAll(): Collection
    {
        return StockMovement::query()
            ->select('ingredient_id', DB::raw('SUM(quantity_delta) as on_hand'))
            ->groupBy('ingredient_id')
            ->pluck('on_hand', 'ingredient_id')
            ->map(fn ($sum) => (int) $sum);
    }
}
