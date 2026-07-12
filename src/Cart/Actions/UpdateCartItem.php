<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Actions\Concerns\BumpsCartVersionAtomically;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use InvalidArgumentException;

final class UpdateCartItem
{
    use BumpsCartVersionAtomically;

    public function execute(Cart $cart, CartItem $item, int $quantity, ?int $expectedVersion = null): CartItem
    {
        if ($item->cart_id !== $cart->id) {
            throw new InvalidArgumentException('Cart item does not belong to this cart.');
        }

        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be positive.');
        }

        return $this->withCartVersionBump($cart, $expectedVersion, function () use ($item, $quantity): CartItem {
            $item->update(['quantity' => $quantity]);

            return $item->fresh();
        });
    }
}
