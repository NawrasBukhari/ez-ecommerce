<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Exceptions\CartVersionConflictException;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use InvalidArgumentException;

final class UpdateCartItem
{
    public function execute(Cart $cart, CartItem $item, int $quantity, ?int $expectedVersion = null): CartItem
    {
        if ($item->cart_id !== $cart->id) {
            throw new InvalidArgumentException('Cart item does not belong to this cart.');
        }

        if ($expectedVersion !== null && $cart->version !== $expectedVersion) {
            throw CartVersionConflictException::for($cart);
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }

        $item->update(['quantity' => $quantity]);
        $cart->update(['version' => $cart->version + 1]);

        return $item->fresh();
    }
}
