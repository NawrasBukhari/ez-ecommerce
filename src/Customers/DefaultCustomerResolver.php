<?php

namespace EzEcommerce\Customers;

use EzEcommerce\Customers\Contracts\CustomerResolver;
use EzEcommerce\Customers\Data\CustomerIdentity;
use EzEcommerce\Customers\Data\CustomerResolutionContext;
use EzEcommerce\Customers\Models\Customer;

final class DefaultCustomerResolver implements CustomerResolver
{
    public function resolve(CustomerIdentity $identity, CustomerResolutionContext $context): ?Customer
    {
        if ($identity->actorType !== null && $identity->actorId !== null) {
            $existing = Customer::query()
                ->where('actor_type', $identity->actorType)
                ->where('actor_id', $identity->actorId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($context->cart?->customer_id !== null) {
            return $context->cart->customer;
        }

        return Customer::query()->create([
            'actor_type' => $identity->actorType,
            'actor_id' => $identity->actorId,
            'email' => $identity->email,
            'first_name' => $identity->firstName,
            'last_name' => $identity->lastName,
            'phone' => $identity->phone,
            'metadata' => $identity->metadata,
        ]);
    }
}
