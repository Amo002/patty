<?php

namespace App\Models;

use Database\Factories\SaleLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One menu item and quantity on a sale ticket. Never addressed directly (D-031), so no ulid.
 *
 * D-048: it is the movement reference for sales, like DeliveryLine for deliveries. Each
 * (sale line, recipe line) pair has its own movement, so a later refund of k of n units can
 * put back exactly `movement delta / n x k`.
 */
#[UseFactory(SaleLineFactory::class)]
class SaleLine extends Model
{
    use HasFactory;

    protected $fillable = ['sale_id', 'menu_item_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
