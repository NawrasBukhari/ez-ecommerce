<?php

namespace EzEcommerce\Cart;

use EzEcommerce\Cart\Actions\AddItemToCart;
use EzEcommerce\Cart\Actions\ApplyDiscountCode;
use EzEcommerce\Cart\Actions\CalculateCartTotals;
use EzEcommerce\Cart\Actions\CreateGuestCart;
use EzEcommerce\Cart\Actions\MergeCarts;
use EzEcommerce\Cart\Actions\RemoveDiscountCode;
use EzEcommerce\Cart\Actions\RemoveCartItem;
use EzEcommerce\Cart\Actions\UpdateCartItem;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use EzEcommerce\Catalog\Contracts\Purchasable;
use EzEcommerce\Customers\Models\Address;

final class CartManager
{
    public function __construct(
        private readonly CreateGuestCart $createGuestCart,
        private readonly AddItemToCart $addItemToCart,
        private readonly UpdateCartItem $updateCartItem,
        private readonly RemoveCartItem $removeCartItem,
        private readonly ApplyDiscountCode $applyDiscountCode,
        private readonly CalculateCartTotals $calculateCartTotals,
        private readonly MergeCarts $mergeCarts,
        private readonly RemoveDiscountCode $removeDiscountCode,
    ) {}

    /** @return array{cart: Cart, guest_token: string} */
    public function createGuest(string $currency, ?string $guestToken = null): array
    {
        return $this->createGuestCart->execute($currency, $guestToken);
    }

    public function addItem(Cart $cart, Purchasable $purchasable, int $quantity, ?int $expectedVersion = null): CartItem
    {
        return $this->addItemToCart->execute($cart, $purchasable, $quantity, $expectedVersion);
    }

    public function updateItem(Cart $cart, CartItem $item, int $quantity, ?int $expectedVersion = null): CartItem
    {
        return $this->updateCartItem->execute($cart, $item, $quantity, $expectedVersion);
    }

    public function removeItem(Cart $cart, CartItem $item, ?int $expectedVersion = null): void
    {
        $this->removeCartItem->execute($cart, $item, $expectedVersion);
    }

    public function applyDiscount(Cart $cart, string $code, ?int $expectedVersion = null): Cart
    {
        return $this->applyDiscountCode->execute($cart, $code, $expectedVersion);
    }

    public function calculateTotals(Cart $cart, ?string $shippingMethod = null, ?Address $shippingAddress = null, ?int $expectedVersion = null): Cart
    {
        return $this->calculateCartTotals->execute($cart, $shippingMethod, $shippingAddress, $expectedVersion);
    }

    public function totalsHash(Cart $cart, ?string $shippingMethod = null): string
    {
        return $this->calculateCartTotals->totalsHash($cart, $shippingMethod);
    }

    public function merge(Cart $guestCart, Cart $customerCart): Cart
    {
        return $this->mergeCarts->execute($guestCart, $customerCart);
    }

    public function removeDiscount(Cart $cart, ?string $code = null, ?int $expectedVersion = null): Cart
    {
        return $this->removeDiscountCode->execute($cart, $code, $expectedVersion);
    }
}
