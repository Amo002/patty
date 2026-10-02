<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[UseFactory(MenuItemFactory::class)]
class MenuItem extends Model
{
    use HasFactory, HasPublicUlid, LogsActivity;

    protected $fillable = ['name', 'image_path'];

    /**
     * D-021: field changes (a rename) come from the trait, dirty fields only.
     * The named event `recipe.replaced` comes from MenuService, because recipe
     * lines are not columns of this model.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'image_path'])->logOnlyDirty();
    }

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
