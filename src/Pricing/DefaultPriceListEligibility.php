<?php

namespace EzEcommerce\Pricing;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Pricing\Contracts\PriceListEligibility;
use EzEcommerce\Pricing\Models\PriceList;

final class DefaultPriceListEligibility implements PriceListEligibility
{
    public function allows(PriceList $priceList, ?Customer $customer, Cart $cart): bool
    {
        if ($priceList->currency !== $cart->currency) {
            return false;
        }

        $allowedCodes = config('ez-ecommerce.pricing.allowed_price_list_codes', []);

        if ($allowedCodes === [] || $allowedCodes === null) {
            return true;
        }

        $code = $priceList->code ?? null;

        return is_string($code) && in_array($code, $allowedCodes, true);
    }
}
