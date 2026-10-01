<?php

namespace App\Models;

use App\Enums\Unit;
use App\Models\Concerns\HasPublicUlid;
use App\Support\Tolerance;
use Database\Factories\IngredientFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(IngredientFactory::class)]
class Ingredient extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = [
        'name',
        'unit',
        'over_tolerance_bps',
        'under_tolerance_bps',
        'over_tolerance_cap',
        'image_path',
    ];

    protected function casts(): array
    {
        return [
            'unit' => Unit::class,
            'over_tolerance_bps' => 'integer',
            'under_tolerance_bps' => 'integer',
            'over_tolerance_cap' => 'integer',
        ];
    }

    /**
     * D-035: the tolerance a new PO line should snapshot.
     *
     * Each field falls back to the unit default independently, so an ingredient
     * can override only the cap and keep the default percentages. The cap is
     * the one nullable default, so "no override" is tested with a null check
     * on the ingredient's own column, never with a falsy check (0 is a valid
     * override: it means "no tolerance").
     */
    public function effectiveTolerance(): Tolerance
    {
        $defaults = config('patty.tolerance.defaults.'.$this->unit->value);

        $overrides = [
            'over_bps' => $this->over_tolerance_bps,
            'under_bps' => $this->under_tolerance_bps,
            'over_cap' => $this->over_tolerance_cap,
        ];

        $hasOverride = count(array_filter($overrides, fn ($value) => $value !== null)) > 0;

        return new Tolerance(
            overBps: $overrides['over_bps'] ?? $defaults['over_bps'],
            underBps: $overrides['under_bps'] ?? $defaults['under_bps'],
            overCap: $overrides['over_cap'] ?? $defaults['over_cap'],
            source: $hasOverride ? Tolerance::SOURCE_INGREDIENT : Tolerance::SOURCE_DEFAULT,
        );
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }

    public function purchaseOrderLines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }
}
