<?php

namespace EzEcommerce\Shipping\Contracts;

use EzEcommerce\Shipping\Data\ShippingMethodQuote;
use EzEcommerce\Shipping\Data\ShippingRequest;

interface ShippingCalculator
{
    public function quote(ShippingRequest $request): ShippingMethodQuote;
}
