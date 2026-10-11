<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[UseFactory(SaleFactory::class)]
class Sale extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = ['number', 'pos_reference', 'sold_at'];

    protected function casts(): array
    {
        return ['sold_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SaleLine::class);
    }

    /**
     * "Classic Burger x2" for a one-line ticket, "3 items" otherwise. One rule for the audit
     * description and the movement history label. Needs `lines.menuItem` loaded.
     */
    public function summary(): string
    {
        if ($this->lines->count() === 1) {
            $line = $this->lines->first();

            return "{$line->menuItem->name} x{$line->quantity}";
        }

        return $this->lines->count().' items';
    }

    /**
     * Movements that reference the sale itself. Only sales recorded before D-048 have any; new
     * ones reference their SaleLine (the ledger is append-only, so those rows could not be moved).
     */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }
}
