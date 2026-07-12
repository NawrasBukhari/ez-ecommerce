<?php

namespace EzEcommerce\Shipping\Data;

use EzEcommerce\Core\Money\Money;

final readonly class ShippingMethodQuote
{
    public function __construct(
        public string $method,
        public string $label,
        public Money $amount,
    ) {
    }
}
