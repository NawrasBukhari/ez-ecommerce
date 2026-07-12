<?php

namespace EzEcommerce\Inventory;

use DateTimeImmutable;
use EzEcommerce\Inventory\Contracts\ReservationPolicy;
use EzEcommerce\Orders\Models\Order;

final class ConfigReservationPolicy implements ReservationPolicy
{
    public function expiresAt(Order $order, string $paymentMethod, DateTimeImmutable $now): ?DateTimeImmutable
    {
        $ttl = config("ez-ecommerce.inventory.reservation_ttl.{$paymentMethod}")
            ?? config('ez-ecommerce.inventory.reservation_ttl.default', 30);

        if ($ttl === 0) {
            return null;
        }

        return $now->modify("+{$ttl} minutes");
    }

    public function shouldCommitImmediately(Order $order, string $paymentMethod): bool
    {
        $ttl = config("ez-ecommerce.inventory.reservation_ttl.{$paymentMethod}")
            ?? config('ez-ecommerce.inventory.reservation_ttl.default', 30);

        return $ttl === 0 || $order->grand_total_minor === 0;
    }
}
