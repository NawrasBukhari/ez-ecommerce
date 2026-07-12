<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Core\Enums\CartStatus;

final class PurgeExpiredCarts
{
    public function execute(): int
    {
        return Cart::query()
            ->whereNotNull('guest_token_hash')
            ->where('expires_at', '<', now())
            ->where('status', '!=', CartStatus::Expired)
            ->update(['status' => CartStatus::Expired]);
    }
}
