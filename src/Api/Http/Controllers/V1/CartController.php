<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\CartItemResource;
use EzEcommerce\Api\Http\Resources\CartResource;
use EzEcommerce\Cart\Actions\CalculateCartTotals;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartItem;
use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\CommerceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class CartController extends Controller
{
    public function __construct(
        private readonly CommerceManager $commerce,
    ) {
    }

    public function storeGuest(Request $request): JsonResponse
    {
        $currency = $request->input('currency', config('ez-ecommerce.currency.default', 'AED'));

        ['cart' => $cart, 'guest_token' => $guestToken] = $this->commerce->cart()->createGuest($currency);
        $cart->load('items');

        return (new CartResource($cart))
            ->additional(['guest_token' => $guestToken])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Cart $cart): CartResource
    {
        $cart->load('items.purchasable');

        return new CartResource($cart);
    }

    public function storeItem(Request $request, Cart $cart): CartItemResource
    {
        $validated = $request->validate([
            'variant_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'expected_version' => ['sometimes', 'integer'],
        ]);

        $variant = ProductVariant::query()
            ->where('public_id', $validated['variant_id'])
            ->firstOrFail();

        $item = $this->commerce->cart()->addItem(
            $cart,
            $variant,
            $validated['quantity'],
            $validated['expected_version'] ?? null,
        );

        $item->load('purchasable');

        return new CartItemResource($item);
    }

    public function updateItem(Request $request, Cart $cart, CartItem $item): CartItemResource
    {
        $validated = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'expected_version' => ['sometimes', 'integer'],
        ]);

        $item = $this->commerce->cart()->updateItem(
            $cart,
            $item,
            $validated['quantity'],
            $validated['expected_version'] ?? null,
        );

        $item->load('purchasable');

        return new CartItemResource($item);
    }

    public function destroyItem(Request $request, Cart $cart, CartItem $item): JsonResponse
    {
        $validated = $request->validate([
            'expected_version' => ['sometimes', 'integer'],
        ]);

        $this->commerce->cart()->removeItem(
            $cart,
            $item,
            $validated['expected_version'] ?? null,
        );

        return response()->json(null, 204);
    }

    public function applyDiscount(Request $request, Cart $cart): CartResource
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'expected_version' => ['sometimes', 'integer'],
        ]);

        $cart = $this->commerce->cart()->applyDiscount(
            $cart,
            $validated['code'],
            $validated['expected_version'] ?? null,
        );

        $cart->load('items.purchasable');

        return new CartResource($cart);
    }

    public function removeDiscount(Request $request, Cart $cart): CartResource
    {
        $validated = $request->validate([
            'code' => ['sometimes', 'nullable', 'string'],
            'expected_version' => ['sometimes', 'integer'],
        ]);

        $cart = $this->commerce->cart()->removeDiscount(
            $cart,
            $validated['code'] ?? null,
            $validated['expected_version'] ?? null,
        );

        $cart->load('items.purchasable');

        return new CartResource($cart);
    }

    public function calculate(Request $request, Cart $cart): CartResource
    {
        $validated = $request->validate([
            'shipping_method' => ['sometimes', 'nullable', 'string'],
            'price_list_id' => ['sometimes', 'nullable', 'string'],
            'expected_version' => ['sometimes', 'integer'],
        ]);

        if (! empty($validated['price_list_id'])) {
            $metadata = $cart->metadata instanceof \ArrayObject
                ? $cart->metadata->getArrayCopy()
                : (array) ($cart->metadata ?? []);
            $metadata['price_list_id'] = $validated['price_list_id'];
            $cart->update(['metadata' => $metadata]);
        }

        $cart = $this->commerce->cart()->calculateTotals(
            $cart,
            $validated['shipping_method'] ?? null,
            null,
            $validated['expected_version'] ?? null,
        );

        $cart->load('items.purchasable');

        $totalsHash = app(CalculateCartTotals::class)
            ->totalsHash($cart, $validated['shipping_method'] ?? null);

        return (new CartResource($cart))->additional(['totals_hash' => $totalsHash]);
    }

    public function merge(Request $request): CartResource
    {
        $validated = $request->validate([
            'guest_cart_id' => ['required', 'string'],
            'customer_cart_id' => ['required', 'string'],
            'guest_token' => ['required', 'string'],
        ]);

        $guestCart = Cart::query()
            ->where('public_id', $validated['guest_cart_id'])
            ->firstOrFail();

        if ($guestCart->guest_token_hash === null
            || ! hash_equals($guestCart->guest_token_hash, hash('sha256', $validated['guest_token']))) {
            abort(403, 'Invalid guest cart token.');
        }

        $customerCart = Cart::query()
            ->where('public_id', $validated['customer_cart_id'])
            ->firstOrFail();

        if ($customerCart->customer_id === null) {
            abort(422, 'Target cart must belong to a customer.');
        }

        $cart = $this->commerce->cart()->merge($guestCart, $customerCart);
        $cart->load('items.purchasable');

        return new CartResource($cart);
    }
}
