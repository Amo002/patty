<?php

namespace App\Exceptions\Domain;

/**
 * A refund names a menu item that is not a line of the sale (D-049, 422).
 *
 * `errors` keys each offending row by its request position (`lines.N.menu_item_id`) so the UI can mark it.
 */
class ItemNotOnSale extends DomainException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(string $message, private readonly array $errors)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'item_not_on_sale';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param  list<array{index: int, item: string}>  $breaches  index is the 0-based request row
     */
    public static function forLines(string $saleNumber, array $breaches): self
    {
        $errors = [];

        foreach ($breaches as $b) {
            $errors["lines.{$b['index']}.menu_item_id"][] = sprintf('Line %d: %s is not on %s.', $b['index'] + 1, $b['item'], $saleNumber);
        }

        // The message is the first breach's text without its "Line N: " prefix.
        $first = $breaches[0];

        return new self(sprintf('%s is not on %s.', $first['item'], $saleNumber), $errors);
    }
}
