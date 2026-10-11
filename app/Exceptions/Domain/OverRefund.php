<?php

namespace App\Exceptions\Domain;

/**
 * A refund line asks for more than is left on the sale line (D-049, 422).
 *
 * Like OverDelivery: the message speaks for the whole request, `errors` names every
 * offending row by its request position (`lines.N.quantity`).
 */
class OverRefund extends DomainException
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
        return 'over_refund';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @param  list<array{index: int, item: string, left: int}>  $breaches  index is the 0-based request row
     */
    public static function forLines(string $saleNumber, array $breaches): self
    {
        $errors = [];

        foreach ($breaches as $b) {
            $errors["lines.{$b['index']}.quantity"][] = sprintf('Line %d: %s', $b['index'] + 1, self::text($b['item'], $b['left'], $saleNumber));
        }

        // The message is the first breach's text without its "Line N: " prefix.
        $first = $breaches[0];

        return new self(self::text($first['item'], $first['left'], $saleNumber), $errors);
    }

    private static function text(string $item, int $left, string $saleNumber): string
    {
        return sprintf('only %d %s left to refund on %s.', $left, $item, $saleNumber);
    }
}
