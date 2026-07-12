<?php

namespace EzEcommerce\Cart\Exceptions;

use EzEcommerce\Cart\Models\Cart;
use RuntimeException;

final class CartTotalsChangedException extends RuntimeException
{
    public static function for(Cart $cart): self
    {
        return new self("Cart [{$cart->public_id}] totals changed since last calculation.");
    }
}
