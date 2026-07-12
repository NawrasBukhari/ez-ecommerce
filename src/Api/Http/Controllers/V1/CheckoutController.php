<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\CheckoutResultResource;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\CommerceManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CheckoutController extends Controller
{
    public function __construct(
        private readonly CommerceManager $commerce,
    ) {}

    public function store(Request $request): CheckoutResultResource
    {
        $validated = $request->validate([
            'cart_id' => ['required', 'string'],
            'shipping_method' => ['sometimes', 'nullable', 'string'],
            'payment_method' => ['sometimes', 'nullable', 'string'],
            'expected_totals_hash' => ['sometimes', 'nullable', 'string'],
        ]);

        $cart = Cart::query()
            ->where('public_id', $validated['cart_id'])
            ->firstOrFail();

        $idempotencyKey = $request->header('Idempotency-Key');
        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            abort(422, 'Idempotency-Key header is required.');
        }

        $result = $this->commerce->checkout()
            ->for($cart)
            ->shippingMethod($validated['shipping_method'] ?? null)
            ->paymentMethod($validated['payment_method'] ?? null)
            ->place(
                idempotencyKey: $idempotencyKey,
                expectedTotalsHash: $validated['expected_totals_hash'] ?? null,
            );

        return new CheckoutResultResource($result);
    }
}
