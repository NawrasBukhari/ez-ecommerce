<?php

namespace EzEcommerce\Pricing\Contracts;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Pricing\Models\PriceList;

interface PriceListEligibility
{
    public function allows(PriceList $priceList, ?Customer $customer, Cart $cart): bool;
}
