<?php

namespace EzEcommerce\Customers\Data;

use EzEcommerce\Cart\Models\Cart;

final readonly class CustomerResolutionContext
{
    public function __construct(
        public ?Cart $cart = null,
        public string $source = 'checkout',
    ) {}
}
