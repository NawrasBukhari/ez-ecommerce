<?php

namespace EzEcommerce\Facades;

use EzEcommerce\Cart\CartManager;
use EzEcommerce\Catalog\CatalogManager;
use EzEcommerce\Checkout\CheckoutManager;
use EzEcommerce\CommerceManager;
use EzEcommerce\Inventory\InventoryManager;
use EzEcommerce\Orders\OrdersManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static CartManager cart()
 * @method static CheckoutManager checkout()
 * @method static CatalogManager catalog()
 * @method static InventoryManager inventory()
 * @method static OrdersManager orders()
 *
 * @see CommerceManager
 */
class EzEcommerce extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return CommerceManager::class;
    }
}
