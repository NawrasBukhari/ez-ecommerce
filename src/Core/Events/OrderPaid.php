<?php

namespace EzEcommerce\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class OrderPaid implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $orderId,
        public string $orderPublicId,
        public int $paymentId,
    ) {
    }
}
