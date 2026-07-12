<?php

namespace EzEcommerce\Shipping;

use EzEcommerce\Core\Money\Money;
use EzEcommerce\Shipping\Contracts\ShippingCalculator;
use EzEcommerce\Shipping\Data\ShippingMethodQuote;
use EzEcommerce\Shipping\Data\ShippingRequest;

final class FlatShippingCalculator implements ShippingCalculator
{
    public function quote(ShippingRequest $request): ShippingMethodQuote
    {
        $requiresShipping = false;
        foreach ($request->lines as $line) {
            if ($line['requires_shipping']) {
                $requiresShipping = true;
                break;
            }
        }

        if (! $requiresShipping) {
            return new ShippingMethodQuote(
                method: $request->method ?? 'flat',
                label: 'No shipping required',
                amount: Money::zero($request->currency),
            );
        }

        $amountMinor = (int) config('ez-ecommerce.shipping.flat_rate_minor', 1000);

        return new ShippingMethodQuote(
            method: $request->method ?? 'flat',
            label: 'Flat rate shipping',
            amount: Money::fromMinor($amountMinor, $request->currency),
        );
    }
}
