<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUlid;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[UseFactory(MenuItemFactory::class)]
class MenuItem extends Model
{
    use HasFactory, HasPublicUlid;

    protected $fillable = ['name', 'image_path'];

    public function recipeLines(): HasMany
    {
        return $this->hasMany(RecipeLine::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }
}
