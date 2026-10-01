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
        );
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
