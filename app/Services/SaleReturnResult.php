<?php

namespace App\Services;

use App\Models\SaleReturn;

/**
 * What SaleReturnService::record() returns: the refund, and whether it was an
 * existing one answered again (HTTP 200) rather than a new one (201).
 */
final readonly class SaleReturnResult
{
    public function __construct(public SaleReturn $return, public bool $replayed) {}
}
