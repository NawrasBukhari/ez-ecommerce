<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Actions\Concerns\BumpsCartVersionAtomically;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use EzEcommerce\Catalog\Contracts\Purchasable;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Customers\Models\CustomerGroup;
use EzEcommerce\Core\Events\CartItemAdded;
use EzEcommerce\Pricing\Actions\ResolveCartPriceList;
use EzEcommerce\Pricing\Contracts\PriceResolver;
use EzEcommerce\Pricing\Data\PricingContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

final class AddItemToCart
{
    use BumpsCartVersionAtomically;

    public function __construct(
        private readonly PriceResolver $priceResolver,
        private readonly ResolveCartPriceList $resolveCartPriceList,
    ) {
    }

    public function execute(Cart $cart, Purchasable $purchasable, int $quantity, ?int $expectedVersion = null): CartItem
    {
        return $this->withCartVersionBump($cart, $expectedVersion, function (Cart $locked) use ($purchasable, $quantity): CartItem {
            $locked->loadMissing('customer.customerGroup');
            $priceList = $this->resolveCartPriceList->for($locked);

            $customer = $locked->customer;
            $customerModel = $customer instanceof Customer ? $customer : null;
            $customerGroup = $customerModel?->customerGroup;
            $customerGroupModel = $customerGroup instanceof CustomerGroup ? $customerGroup : null;

            $quote = $this->priceResolver->resolve($purchasable, new PricingContext(
                currency: $locked->currency,
                quantity: $quantity,
                customer: $customerModel,
                customerGroup: $customerGroupModel,
                priceList: $priceList,
            ));

            $item = CartItem::query()->create([
                'cart_id' => $locked->id,
                'purchasable_type' => $purchasable->purchasableType(),
                'purchasable_id' => $purchasable instanceof Model
                    ? $purchasable->getKey()
                    : null,
                'quantity' => $quantity,
                'unit_price_minor' => $quote->unitPrice->minorAmount,
                'currency' => $quote->unitPrice->currency,
                'metadata' => ['price_source' => $quote->source, 'price_quote_hash' => $quote->fingerprint()],
            ]);

            Event::dispatch(new CartItemAdded($locked->id, $item->id, $purchasable->purchasableType()));

            return $item;
        });
    }
}
