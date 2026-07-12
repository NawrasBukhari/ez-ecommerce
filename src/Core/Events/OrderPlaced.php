<?php

namespace EzEcommerce\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class OrderPlaced implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $orderId,
        public string $orderPublicId,
    ) {
    }
}
