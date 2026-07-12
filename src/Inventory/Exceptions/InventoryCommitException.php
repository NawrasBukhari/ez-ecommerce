<?php

namespace EzEcommerce\Inventory\Exceptions;

use RuntimeException;

final class InventoryCommitException extends RuntimeException
{
    public static function uncommittedReservationsUnavailable(int $orderId): self
    {
        return new self("Order [{$orderId}] has no committable inventory reservations.");
    }

    public static function reservationsReleased(int $orderId): self
    {
        return new self("Order [{$orderId}] inventory reservations were released.");
    }
}
