<?php

namespace App\Exceptions\Domain;

use App\Models\MenuItem;

/**
 * A menu item with no recipe cannot be sold: the sale would deduct nothing and
 * leave a sale record that explains no stock change (FR-5).
 */
class MenuItemNotSellable extends DomainException
{
    public function __construct(MenuItem $item)
    {
        parent::__construct("{$item->name} has no recipe, so it cannot be sold. Add its ingredients first.");
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'menu_item_not_sellable';
    }
}
