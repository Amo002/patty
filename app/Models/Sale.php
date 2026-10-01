<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[UseFactory(SaleFactory::class)]
class Sale extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = ['number', 'menu_item_id', 'quantity', 'pos_reference', 'sold_at'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sold_at' => 'datetime',
        ];
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
