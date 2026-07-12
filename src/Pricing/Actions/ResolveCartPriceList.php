<?php

namespace EzEcommerce\Pricing\Actions;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Pricing\Contracts\PriceListEligibility;
use EzEcommerce\Pricing\Models\PriceList;
use InvalidArgumentException;

final class ResolveCartPriceList
{
    public function __construct(
        private readonly PriceListEligibility $priceListEligibility,
    ) {
    }

    public function for(Cart $cart, ?string $priceListPublicId = null): ?PriceList
    {
        $metadata = $cart->metadata instanceof \ArrayObject
            ? $cart->metadata->getArrayCopy()
            : (array) ($cart->metadata ?? []);

        $priceListId = $priceListPublicId ?? ($metadata['price_list_id'] ?? null);

        if (! is_string($priceListId) || $priceListId === '') {
            return null;
        }

        $priceList = PriceList::query()->where('public_id', $priceListId)->first();

        if ($priceList === null) {
            throw new InvalidArgumentException("Price list [{$priceListId}] was not found.");
        }

        if (! $this->priceListEligibility->allows(
            $priceList,
            $cart->customer instanceof Customer ? $cart->customer : null,
            $cart,
        )) {
            throw new InvalidArgumentException("Price list [{$priceListId}] is not available for this cart.");
        }

        return $priceList;
    }
}
