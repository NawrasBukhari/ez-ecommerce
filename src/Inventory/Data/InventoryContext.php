<?php

namespace EzEcommerce\Inventory\Data;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Orders\Models\Order;

final readonly class InventoryContext
{
    public function __construct(
        public ?Cart $cart = null,
        public ?Order $order = null,
    ) {}
}
