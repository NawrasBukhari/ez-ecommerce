<?php

namespace EzEcommerce\Taxes;

use EzEcommerce\Core\Money\Money;
use EzEcommerce\Taxes\Contracts\TaxCalculator;
use EzEcommerce\Taxes\Data\TaxRequest;
use EzEcommerce\Taxes\Data\TaxResult;

final class JurisdictionTaxCalculator implements TaxCalculator
{
    public function calculate(TaxRequest $request): TaxResult
    {
        $country = $request->shippingAddress?->country_code;
        $jurisdictions = config('ez-ecommerce.tax.jurisdictions', []);
        $rate = is_string($country) && isset($jurisdictions[$country])
            ? (float) $jurisdictions[$country]
            : (float) config('ez-ecommerce.tax.rate', 0.05);

        $taxableBase = $request->subtotal;

        if (config('ez-ecommerce.pricing.tax_after_discounts', true)) {
            $afterDiscount = $taxableBase->minus($request->discountTotal);
            $taxableBase = $afterDiscount->minorAmount < 0
                ? Money::zero($taxableBase->currency)
                : $afterDiscount;
        }

        if (config('ez-ecommerce.pricing.shipping_taxable', true) && ! $request->shippingTotal->isZero()) {
            $taxableBase = $taxableBase->plus($request->shippingTotal);
        }

        $taxMinor = (int) round($taxableBase->minorAmount * $rate);

        return new TaxResult(
            total: Money::fromMinor($taxMinor, $request->subtotal->currency),
            breakdown: [['label' => 'Tax', 'amount' => Money::fromMinor($taxMinor, $request->subtotal->currency), 'rate' => $rate]],
        );
    }
}
