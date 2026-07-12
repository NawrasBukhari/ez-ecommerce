<?php

namespace EzEcommerce\Api\Http\Middleware;

use Closure;
use EzEcommerce\Api\Http\Middleware\Concerns\ValidatesCartExpiry;
use EzEcommerce\Cart\Models\Cart;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateCheckoutCartAccess
{
    use ValidatesCartExpiry;

    public function handle(Request $request, Closure $next): Response
    {
        $cartId = $request->input('cart_id');
        if (! is_string($cartId) || $cartId === '') {
            abort(422, 'cart_id is required.');
        }

        $cart = Cart::query()->where('public_id', $cartId)->firstOrFail();

        $this->rejectIfCartExpired($cart);

        if ($request->filled('price_list_id')) {
            $metadata = $cart->metadata instanceof \ArrayObject
                ? $cart->metadata->getArrayCopy()
                : (array) ($cart->metadata ?? []);
            $metadata['price_list_id'] = $request->string('price_list_id');
            $cart->update(['metadata' => $metadata]);
        }

        if ($this->guestTokenMatches($request, $cart)) {
            return $next($request);
        }

        if ($cart->customer_id !== null && $this->customerOwnsCart($request, $cart)) {
            return $next($request);
        }

        abort(403, 'Unauthorized cart access.');
    }

    private function guestTokenMatches(Request $request, Cart $cart): bool
    {
        if ($cart->guest_token_hash === null) {
            return false;
        }

        $token = $request->header('X-Guest-Cart-Token');

        return is_string($token)
            && hash_equals($cart->guest_token_hash, hash('sha256', $token));
    }

    private function customerOwnsCart(Request $request, Cart $cart): bool
    {
        $user = $request->user();

        if ($user === null) {
            return false;
        }

        $customerId = $user->commerce_customer_id ?? null;

        return $customerId !== null && (int) $customerId === (int) $cart->customer_id;
    }
}
