<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(SupplierFactory::class)]
class Supplier extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = ['name', 'email', 'phone'];

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
