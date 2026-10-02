<?php

namespace App\Exceptions\Domain;

/**
 * A delivery line would take a line's received total above its max_receivable (D-035, 422).
 *
 * The message speaks for the whole request; `errors` names each offending line
 * by its position in the request (`lines.N.quantity`) so the UI can mark the row.
 */
class OverDelivery extends DomainException
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
        return 'over_delivery';
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Quantities are shown in base units with thousands separators (1,100 g), never converted.
     *
     * @param  array<int, array{index: int, ingredient: string, unit: string, attempted: int, received: int, limit: int}>  $breaches  attempted is the would-be total
     */
    public static function forLines(array $breaches): self
    {
        $errors = [];

        foreach ($breaches as $b) {
            $errors["lines.{$b['index']}.quantity"][] = sprintf(
                '%s: %s %s is above the %s %s limit.',
                $b['ingredient'], number_format($b['attempted']), $b['unit'], number_format($b['limit']), $b['unit'],
            );
        }

        $first = $breaches[0];
        $message = sprintf(
            '%s: receiving %s %s would bring the total to %s %s, above the %s %s limit.',
            $first['ingredient'],
            number_format($first['attempted'] - $first['received']), $first['unit'],
            number_format($first['attempted']), $first['unit'],
            number_format($first['limit']), $first['unit'],
        );

        if (count($breaches) > 1) {
            $message .= ' '.(count($breaches) - 1).' more line(s) are also over their limit.';
        }

        return new self($message, $errors);
    }
}
