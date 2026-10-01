<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\DeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(DeliveryFactory::class)]
class Delivery extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = ['number', 'purchase_order_id', 'received_at', 'note'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryLine::class);
    }
}
