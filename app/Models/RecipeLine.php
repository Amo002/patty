<?php

namespace App\Models;

use Database\Factories\RecipeLineFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Never addressed directly (D-031), so it has no ulid: it is read and
 * replaced through its menu item.
 */
#[UseFactory(RecipeLineFactory::class)]
class RecipeLine extends Model
{
    use HasFactory;

    protected $fillable = ['menu_item_id', 'ingredient_id', 'quantity'];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
