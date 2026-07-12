<?php

namespace EzEcommerce\Pricing\Contracts;

use EzEcommerce\Catalog\Contracts\Purchasable;
use EzEcommerce\Pricing\Data\PriceQuote;
use EzEcommerce\Pricing\Data\PricingContext;

interface PriceResolver
{
    public function resolve(Purchasable $purchasable, PricingContext $context): PriceQuote;
}
