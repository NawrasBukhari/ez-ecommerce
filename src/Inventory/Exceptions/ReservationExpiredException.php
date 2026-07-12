<?php

namespace EzEcommerce\Inventory\Exceptions;

use RuntimeException;

final class ReservationExpiredException extends RuntimeException
{
    public static function forReservation(int $reservationId): self
    {
        return new self("Inventory reservation [{$reservationId}] has expired.");
    }
}
