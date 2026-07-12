<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Exceptions\CartVersionConflictException;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use InvalidArgumentException;

final class RemoveCartItem
{
    public function execute(Cart $cart, CartItem $item, ?int $expectedVersion = null): void
    {
        if ($item->cart_id !== $cart->id) {
            throw new InvalidArgumentException('Cart item does not belong to this cart.');
        }

        if ($expectedVersion !== null && $cart->version !== $expectedVersion) {
            throw CartVersionConflictException::for($cart);
        }

        $item->delete();
        $cart->update(['version' => $cart->version + 1]);
    }
}
