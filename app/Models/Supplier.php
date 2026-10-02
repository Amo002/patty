<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[UseFactory(SupplierFactory::class)]
class Supplier extends Model
{
    use HasFactory, HasPublicUlid, LogsActivity;

    protected $fillable = ['name', 'email', 'phone'];

    /**
     * D-021: contact changes, dirty fields only.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'email', 'phone'])->logOnlyDirty();
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
