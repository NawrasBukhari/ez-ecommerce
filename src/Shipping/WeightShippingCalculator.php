<?php

namespace EzEcommerce\Shipping;

use EzEcommerce\Core\Money\Money;
use EzEcommerce\Shipping\Contracts\ShippingCalculator;
use EzEcommerce\Shipping\Data\ShippingMethodQuote;
use EzEcommerce\Shipping\Data\ShippingRequest;

final class WeightShippingCalculator implements ShippingCalculator
{
    public function quote(ShippingRequest $request): ShippingMethodQuote
    {
        $totalGrams = 0;
        $requiresShipping = false;

        foreach ($request->lines as $line) {
            if (! ($line['requires_shipping'] ?? false)) {
                continue;
            }

            $requiresShipping = true;
            $grams = (int) ($line['weight_grams'] ?? 0);
            $totalGrams += $grams * (int) ($line['quantity'] ?? 1);
        }

        if (! $requiresShipping) {
            return new ShippingMethodQuote(
                method: 'weight',
                label: 'No shipping required',
                amount: Money::zero($request->currency),
            );
        }

        $baseMinor = (int) config('ez-ecommerce.shipping.weight.base_minor', 1000);
        $perKgMinor = (int) config('ez-ecommerce.shipping.weight.per_kg_minor', 500);
        $kg = max(1, (int) ceil($totalGrams / 1000));
        $amountMinor = $baseMinor + ($perKgMinor * $kg);

        return new ShippingMethodQuote(
            method: 'weight',
            label: 'Weight-based shipping',
            amount: Money::fromMinor($amountMinor, $request->currency),
        );
    }
}
