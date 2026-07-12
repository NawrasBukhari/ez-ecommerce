<?php

namespace EzEcommerce\Shipping\Data;

use EzEcommerce\Customers\Models\Address;

final readonly class ShippingRequest
{
    /** @param  list<array{requires_shipping: bool, weight_grams: ?int, quantity: int}>  $lines */
    public function __construct(
        public string $currency,
        public ?string $method = null,
        public ?Address $shippingAddress = null,
        public array $lines = [],
    ) {
    }
}
