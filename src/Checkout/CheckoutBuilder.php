<?php

namespace EzEcommerce\Checkout;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Checkout\Actions\PlaceOrder;
use EzEcommerce\Customers\Data\CustomerIdentity;
use EzEcommerce\Customers\Models\Address;

final class CheckoutBuilder
{
    private ?Address $shippingAddress = null;

    private ?Address $billingAddress = null;

    private ?string $shippingMethod = null;

    private ?string $paymentMethod = null;

    private ?CustomerIdentity $customerIdentity = null;

    public function __construct(
        private readonly Cart $cart,
        private readonly PlaceOrder $placeOrder,
    ) {
    }

    public function shippingAddress(?Address $address): self
    {
        $this->shippingAddress = $address;

        return $this;
    }

    public function billingAddress(?Address $address): self
    {
        $this->billingAddress = $address;

        return $this;
    }

    public function shippingMethod(?string $method): self
    {
        $this->shippingMethod = $method;

        return $this;
    }

    public function paymentMethod(?string $method): self
    {
        $this->paymentMethod = $method;

        return $this;
    }

    public function customerIdentity(?CustomerIdentity $identity): self
    {
        $this->customerIdentity = $identity;

        return $this;
    }

    public function place(string $idempotencyKey, ?string $expectedTotalsHash = null): CheckoutResult
    {
        return $this->placeOrder->execute(
            cart: $this->cart,
            shippingAddress: $this->shippingAddress,
            billingAddress: $this->billingAddress,
            shippingMethod: $this->shippingMethod,
            paymentMethod: $this->paymentMethod ?? config('ez-ecommerce.drivers.payment.default', 'manual'),
            idempotencyKey: $idempotencyKey,
            expectedTotalsHash: $expectedTotalsHash,
            customerIdentity: $this->customerIdentity,
        );
    }
}
