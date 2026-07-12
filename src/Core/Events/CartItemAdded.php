<?php

namespace EzEcommerce\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class CartItemAdded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $cartId,
        public int $cartItemId,
        public string $purchasableType,
    ) {
    }
}
