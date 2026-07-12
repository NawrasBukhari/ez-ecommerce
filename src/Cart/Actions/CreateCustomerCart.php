<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Core\Enums\CartStatus;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Stores\Contracts\StoreContext;

final class CreateCustomerCart
{
    public function __construct(
        private readonly StoreContext $storeContext,
    ) {
    }

    public function execute(Customer $customer, string $currency): Cart
    {
        $existing = Cart::query()
            ->where('customer_id', $customer->id)
            ->where('status', CartStatus::Active)
            ->whereNull('guest_token_hash')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return Cart::query()->create([
            'customer_id' => $customer->id,
            'store_id' => $this->storeContext->id(),
            'status' => CartStatus::Active,
            'currency' => strtoupper($currency),
            'version' => 0,
        ]);
    }
}
