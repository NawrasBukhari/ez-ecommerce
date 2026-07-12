<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Actions\Concerns\BumpsCartVersionAtomically;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use InvalidArgumentException;

final class RemoveCartItem
{
    use BumpsCartVersionAtomically;

    public function execute(Cart $cart, CartItem $item, ?int $expectedVersion = null): void
    {
        if ($item->cart_id !== $cart->id) {
            throw new InvalidArgumentException('Cart item does not belong to this cart.');
        }

        $this->withCartVersionBump($cart, $expectedVersion, function () use ($item): void {
            $item->delete();
        });
    }
}
