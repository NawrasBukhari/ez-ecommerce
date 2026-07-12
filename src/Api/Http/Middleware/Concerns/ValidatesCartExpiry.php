<?php

namespace EzEcommerce\Api\Http\Middleware\Concerns;

use EzEcommerce\Cart\Models\Cart;

trait ValidatesCartExpiry
{
    protected function rejectIfCartExpired(Cart $cart): void
    {
        if ($cart->expires_at !== null && $cart->expires_at < now()) {
            abort(410, 'Cart has expired.');
        }
    }
}
