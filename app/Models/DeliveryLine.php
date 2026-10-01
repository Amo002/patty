<?php

namespace App\Models;

use Database\Factories\DeliveryLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Never addressed directly (D-031), so it has no ulid. It is the movement
 * reference for deliveries: each line produces exactly one stock movement.
 */
#[UseFactory(DeliveryLineFactory::class)]
class DeliveryLine extends Model
{
    use HasFactory;

    protected $fillable = ['delivery_id', 'purchase_order_line_id', 'quantity_received'];

    protected function casts(): array
    {
        return ['quantity_received' => 'integer'];
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
