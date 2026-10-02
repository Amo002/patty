<?php

namespace App\Services;

use App\Models\Sale;

/**
 * What SaleService::record() returns: the sale, and whether it was an
 * existing one answered again (HTTP 200) rather than a new one (201).
 */
final readonly class SaleResult
{
    public function __construct(public Sale $sale, public bool $replayed) {}
}
