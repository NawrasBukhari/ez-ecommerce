<?php

namespace EzEcommerce\Pricing\Data;

use DateTimeImmutable;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Customers\Models\CustomerGroup;
use EzEcommerce\Pricing\Models\PriceList;

final readonly class PricingContext
{
    public function __construct(
        public string $currency,
        public int $quantity = 1,
        public ?Customer $customer = null,
        public ?CustomerGroup $customerGroup = null,
        public ?PriceList $priceList = null,
        public ?DateTimeImmutable $at = null,
    ) {
    }
}
