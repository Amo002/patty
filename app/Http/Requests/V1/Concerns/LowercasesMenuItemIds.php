<?php

namespace App\Http\Requests\V1\Concerns;

/**
 * Stored ULIDs are lowercase but the `ulid` rule accepts uppercase, so lowercase `lines.*.menu_item_id`
 * first (G4). Shared by the sale (E25) and the refund (E27) requests.
 */
trait LowercasesMenuItemIds
{
    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (is_array($line) && is_string($line['menu_item_id'] ?? null)) {
                $lines[$index]['menu_item_id'] = strtolower($line['menu_item_id']);
            }
        }

        $this->merge(['lines' => $lines]);
    }
}
