<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use App\Support\Tolerance;
use Database\Factories\PurchaseOrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(PurchaseOrderLineFactory::class)]
class PurchaseOrderLine extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'purchase_order_id',
        'ingredient_id',
        'quantity_ordered',
        'over_tolerance_bps',
        'under_tolerance_bps',
        'over_tolerance_cap',
    ];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'over_tolerance_bps' => 'integer',
            'under_tolerance_bps' => 'integer',
            'over_tolerance_cap' => 'integer',
        ];
    }

    /**
     * D-035: the line's own snapshot, never the ingredient's current settings,
     * so editing an ingredient cannot change an order already in flight.
     */
    public function tolerance(): Tolerance
    {
        return new Tolerance(
            overBps: $this->over_tolerance_bps,
            underBps: $this->under_tolerance_bps,
            overCap: $this->over_tolerance_cap,
            source: Tolerance::SOURCE_SNAPSHOT,
        );
    }

    /**
     * Total delivered against this line: the sum of its delivery lines (never stored).
     *
     * Lists load it in one query with
     * `withSum('deliveryLines as received_sum', 'quantity_received')`; without
     * that it falls back to one query per call, which is fine for a single order.
     */
    public function received(): int
    {
        if (array_key_exists('received_sum', $this->attributes)) {
            return (int) $this->attributes['received_sum'];
        }

        return (int) $this->deliveryLines()->sum('quantity_received');
    }

    /**
     * D-035: complete once received reaches the snapshot's min_to_complete.
     */
    public function isComplete(): bool
    {
        return $this->received() >= $this->minToComplete();
    }

    /**
     * Still expected from the supplier. Zero once complete, even when a
     * within-tolerance shortfall remains (that is underDelivered()).
     */
    public function outstanding(): int
    {
        return $this->isComplete() ? 0 : $this->quantity_ordered - $this->received();
    }

    /**
     * Shortfall accepted as complete under the under-tolerance.
     */
    public function underDelivered(): int
    {
        $received = $this->received();

        return $this->isComplete() && $received < $this->quantity_ordered
            ? $this->quantity_ordered - $received
            : 0;
    }

    /**
     * Quantity received beyond what was ordered (allowed up to maxReceivable()).
     */
    public function overReceived(): int
    {
        return max(0, $this->received() - $this->quantity_ordered);
    }

    public function maxReceivable(): int
    {
        return $this->tolerance()->maxReceivable($this->quantity_ordered);
    }

    public function minToComplete(): int
    {
        return $this->tolerance()->minToComplete($this->quantity_ordered);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function deliveryLines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class);
    }
}
