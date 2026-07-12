<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Exceptions\CartVersionConflictException;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use EzEcommerce\Catalog\Contracts\Purchasable;
use EzEcommerce\Core\Events\CartItemAdded;
use EzEcommerce\Pricing\Contracts\PriceResolver;
use EzEcommerce\Pricing\Data\PricingContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

final class AddItemToCart
{
    public function __construct(
        private readonly PriceResolver $priceResolver,
    ) {
    }

    public function execute(Cart $cart, Purchasable $purchasable, int $quantity, ?int $expectedVersion = null): CartItem
    {
        if ($expectedVersion !== null && $cart->version !== $expectedVersion) {
            throw CartVersionConflictException::for($cart);
        }

        $quote = $this->priceResolver->resolve($purchasable, new PricingContext(
            currency: $cart->currency,
            quantity: $quantity,
            customer: $cart->customer,
        ));

        $item = CartItem::query()->create([
            'cart_id' => $cart->id,
            'purchasable_type' => $purchasable->purchasableType(),
            'purchasable_id' => $purchasable instanceof Model
                ? $purchasable->getKey()
                : null,
            'quantity' => $quantity,
            'unit_price_minor' => $quote->unitPrice->minorAmount,
            'currency' => $quote->unitPrice->currency,
            'metadata' => ['price_source' => $quote->source, 'price_quote_hash' => $quote->fingerprint()],
        ]);

        $cart->update(['version' => $cart->version + 1]);

        Event::dispatch(new CartItemAdded($cart->id, $item->id, $purchasable->purchasableType()));

        return $item;
    }
}
