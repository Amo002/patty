<?php

namespace App\Models;

use Database\Factories\SaleReturnLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * One sale line and the quantity refunded from it. Never addressed directly (D-031), so no ulid.
 *
 * D-049: it is the movement reference for restocks, like SaleLine for sales.
 */
#[UseFactory(SaleReturnLineFactory::class)]
class SaleReturnLine extends Model
{
    use HasFactory;

    protected $fillable = ['sale_return_id', 'sale_line_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class);
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(SaleLine::class);
    }

    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
