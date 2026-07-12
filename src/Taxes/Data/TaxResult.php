<?php

namespace EzEcommerce\Taxes\Data;

use EzEcommerce\Core\Money\Money;

final readonly class TaxResult
{
    /** @param  list<array{label: string, amount: Money, rate: float}>  $breakdown */
    public function __construct(
        public Money $total,
        public array $breakdown = [],
    ) {}
}
