<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SaleReturnFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * D-049: a refund document against one sale. The sale is never changed; "what is left to refund"
 * is derived from the sum of these lines.
 */
#[UseFactory(SaleReturnFactory::class)]
class SaleReturn extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = ['number', 'sale_id', 'restock', 'reason', 'pos_reference', 'returned_at'];

    protected function casts(): array
    {
        return ['restock' => 'boolean', 'returned_at' => 'datetime'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleReturnLine::class);
    }
}
