<?php

namespace App\Enums;

/**
 * D-015: an ingredient has exactly one unit and every quantity is an integer in it.
 */
enum Unit: string
{
    case Gram = 'g';
    case Millilitre = 'ml';
    case Piece = 'piece';

    public function label(): string
    {
        return match ($this) {
            self::Gram => 'Grams',
            self::Millilitre => 'Millilitres',
            self::Piece => 'Pieces',
        };
    }
}
