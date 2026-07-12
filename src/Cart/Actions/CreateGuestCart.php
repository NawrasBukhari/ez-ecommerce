<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\CartStatus;
use EzEcommerce\Stores\Contracts\StoreContext;

final class CreateGuestCart
{
    public function __construct(
        private readonly Clock $clock,
        private readonly StoreContext $storeContext,
    ) {}

    /** @return array{cart: Cart, guest_token: string} */
    public function execute(string $currency, ?string $guestToken = null): array
    {
        $guestToken ??= bin2hex(random_bytes(32));

        $cart = Cart::query()->create([
            'store_id' => $this->storeContext->id(),
            'guest_token_hash' => hash('sha256', $guestToken),
            'status' => CartStatus::Active,
            'currency' => strtoupper($currency),
            'version' => 0,
            'expires_at' => $this->clock->now()->modify('+'.config('ez-ecommerce.cart.guest_ttl_days', 30).' days'),
        ]);

        return ['cart' => $cart, 'guest_token' => $guestToken];
    }
}
