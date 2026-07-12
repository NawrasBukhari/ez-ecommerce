<?php

namespace EzEcommerce;

use EzEcommerce\Cart\CartManager;
use EzEcommerce\Catalog\CatalogManager;
use EzEcommerce\Checkout\CheckoutManager;
use EzEcommerce\Inventory\InventoryManager;
use EzEcommerce\Orders\OrdersManager;
use Illuminate\Contracts\Foundation\Application;

final class CommerceManager
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function cart(): CartManager
    {
        return $this->app->make(CartManager::class);
    }

    public function checkout(): CheckoutManager
    {
        return $this->app->make(CheckoutManager::class);
    }

    public function catalog(): CatalogManager
    {
        return $this->app->make(CatalogManager::class);
    }

    public function inventory(): InventoryManager
    {
        return $this->app->make(InventoryManager::class);
    }

    public function orders(): OrdersManager
    {
        return $this->app->make(OrdersManager::class);
    }
}
