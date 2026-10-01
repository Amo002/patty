<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\ListMenuItemsRequest;
use App\Http\Requests\V1\ReplaceRecipeRequest;
use App\Http\Requests\V1\StoreMenuItemRequest;
use App\Http\Requests\V1\UpdateMenuItemRequest;
use App\Http\Resources\V1\MenuItemResource;
use App\Models\MenuItem;
use App\Services\MenuService;
use Illuminate\Http\JsonResponse;

/**
 * E11 to E15. Validates, calls one MenuService method and responds.
 */
class MenuItemController extends ApiController
{
    public function __construct(private readonly MenuService $menu) {}

    public function index(ListMenuItemsRequest $request): JsonResponse
    {
        return $this->paginated($this->menu->paginate($request->perPage()), MenuItemResource::class);
    }

    public function store(StoreMenuItemRequest $request): JsonResponse
    {
        $item = $this->menu->create($request->validated('name'), $request->recipeLines());

        return $this->created(new MenuItemResource($item), 'Menu item created.');
    }

    public function show(MenuItem $menuItem): JsonResponse
    {
        return $this->success(new MenuItemResource($this->menu->withRecipe($menuItem)));
    }

    public function update(UpdateMenuItemRequest $request, MenuItem $menuItem): JsonResponse
    {
        // G10: a PATCH without a name changes nothing.
        $item = $request->has('name')
            ? $this->menu->rename($menuItem, $request->validated('name'))
            : $this->menu->withRecipe($menuItem);

        return $this->success(new MenuItemResource($item), 'Menu item updated.');
    }

    public function replaceRecipe(ReplaceRecipeRequest $request, MenuItem $menuItem): JsonResponse
    {
        $item = $this->menu->replaceRecipe($menuItem, $request->lines());

        return $this->success(new MenuItemResource($item), 'Recipe updated. It applies to future sales.');
    }
}
