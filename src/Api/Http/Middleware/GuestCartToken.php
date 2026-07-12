<?php

namespace EzEcommerce\Api\Http\Middleware;

use Closure;
use EzEcommerce\Cart\Models\Cart;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class GuestCartToken
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Cart $cart */
        $cart = $request->route('cart');

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

        // ponytail: host app may expose commerce_customer_id on the user model
        $customerId = $user->commerce_customer_id ?? null;

        return $customerId !== null && (int) $customerId === (int) $cart->customer_id;
    }
}
