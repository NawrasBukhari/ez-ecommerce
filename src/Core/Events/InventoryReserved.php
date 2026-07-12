<?php

namespace EzEcommerce\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class InventoryReserved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $reservationId,
        public int $orderId,
        public int $balanceId,
    ) {}
}
