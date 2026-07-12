<?php

namespace EzEcommerce\Shipping;

use EzEcommerce\Shipping\Contracts\ShippingCalculator;
use EzEcommerce\Shipping\Data\ShippingMethodQuote;
use EzEcommerce\Shipping\Data\ShippingRequest;

final class DelegatingShippingCalculator implements ShippingCalculator
{
    public function __construct(
        private readonly FlatShippingCalculator $flat,
        private readonly WeightShippingCalculator $weight,
    ) {}

    public function quote(ShippingRequest $request): ShippingMethodQuote
    {
        return match ($request->method) {
            'weight' => $this->weight->quote($request),
            default => $this->flat->quote($request),
        };
    }
}
