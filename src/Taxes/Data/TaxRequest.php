<?php

namespace EzEcommerce\Taxes\Data;

use EzEcommerce\Core\Money\Money;
use EzEcommerce\Customers\Models\Address;

final readonly class TaxRequest
{
    /** @param  list<array{taxable: bool, amount: Money, tax_category: ?string}>  $lines */
    public function __construct(
        public Money $subtotal,
        public Money $discountTotal,
        public Money $shippingTotal,
        public ?Address $shippingAddress = null,
        public array $lines = [],
    ) {
    }
}
