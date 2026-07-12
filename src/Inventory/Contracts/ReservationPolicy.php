<?php

namespace EzEcommerce\Inventory\Contracts;

use DateTimeImmutable;
use EzEcommerce\Orders\Models\Order;

interface ReservationPolicy
{
    public function expiresAt(Order $order, string $paymentMethod, DateTimeImmutable $now): ?DateTimeImmutable;

    public function shouldCommitImmediately(Order $order, string $paymentMethod): bool;
}
