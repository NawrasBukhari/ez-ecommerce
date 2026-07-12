<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\CheckoutResultResource;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\CommerceManager;
use EzEcommerce\Customers\Data\CustomerIdentity;
use EzEcommerce\Customers\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CheckoutController extends Controller
{
    public function __construct(
        private readonly CommerceManager $commerce,
    ) {
    }

    public function store(Request $request): CheckoutResultResource
    {
        $validated = $request->validate([
            'cart_id' => ['required', 'string'],
            'shipping_method' => ['sometimes', 'nullable', 'string'],
            'payment_method' => ['sometimes', 'nullable', 'string'],
            'expected_totals_hash' => ['required', 'string'],
            'customer' => ['sometimes', 'array'],
            'customer.email' => ['sometimes', 'nullable', 'email'],
            'customer.first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'shipping_address' => ['sometimes', 'array'],
            'shipping_address.line1' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shipping_address.city' => ['required_with:shipping_address', 'string', 'max:255'],
            'shipping_address.state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shipping_address.postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'shipping_address.country_code' => ['required_with:shipping_address', 'string', 'size:2'],
            'billing_address' => ['sometimes', 'array'],
            'billing_address.line1' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'billing_address.city' => ['required_with:billing_address', 'string', 'max:255'],
            'billing_address.state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'billing_address.postal_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'billing_address.country_code' => ['required_with:billing_address', 'string', 'size:2'],
        ]);

        $cart = Cart::query()
            ->where('public_id', $validated['cart_id'])
            ->firstOrFail();

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            abort(422, 'Idempotency-Key header is required.');
        }

        $customer = $validated['customer'] ?? [];
        $identity = new CustomerIdentity(
            email: $customer['email'] ?? null,
            firstName: $customer['first_name'] ?? null,
            lastName: $customer['last_name'] ?? null,
            phone: $customer['phone'] ?? null,
        );

        $builder = $this->commerce->checkout()
            ->for($cart)
            ->restrictPublicPaymentMethods()
            ->shippingMethod($validated['shipping_method'] ?? null)
            ->paymentMethod($validated['payment_method'] ?? null)
            ->customerIdentity($identity);

        if (isset($validated['shipping_address'])) {
            $builder->shippingAddress($this->addressFromPayload($validated['shipping_address'], 'shipping'));
        }

        if (isset($validated['billing_address'])) {
            $builder->billingAddress($this->addressFromPayload($validated['billing_address'], 'billing'));
        }

        $result = $builder->place(
            idempotencyKey: $idempotencyKey,
            expectedTotalsHash: $validated['expected_totals_hash'],
        );

        return new CheckoutResultResource($result);
    }

    /** @param  array<string, mixed>  $payload */
    private function addressFromPayload(array $payload, string $type): Address
    {
        return new Address([
            'type' => $type,
            'line1' => $payload['line1'],
            'line2' => $payload['line2'] ?? null,
            'city' => $payload['city'],
            'state' => $payload['state'] ?? null,
            'postal_code' => $payload['postal_code'] ?? null,
            'country_code' => strtoupper((string) $payload['country_code']),
        ]);
    }
}
