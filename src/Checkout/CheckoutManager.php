<?php

namespace EzEcommerce\Checkout;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Checkout\Actions\PlaceOrder;

final class CheckoutManager
{
    public function __construct(
        private readonly PlaceOrder $placeOrder,
    ) {
    }

    public function for(Cart $cart): CheckoutBuilder
    {
        return new CheckoutBuilder($cart, $this->placeOrder);
    }
}
