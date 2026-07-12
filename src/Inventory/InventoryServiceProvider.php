<?php

namespace EzEcommerce\Inventory;

use EzEcommerce\Inventory\Contracts\InventoryAllocator;
use EzEcommerce\Inventory\Contracts\ReservationPolicy;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InventoryAllocator::class, DefaultWarehouseAllocator::class);
        $this->app->bind(ReservationPolicy::class, ConfigReservationPolicy::class);
    }
}
