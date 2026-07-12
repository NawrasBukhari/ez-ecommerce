<?php

namespace EzEcommerce\Inventory\Contracts;

use EzEcommerce\Catalog\Contracts\Stockable;
use EzEcommerce\Inventory\Data\InventoryAllocation;
use EzEcommerce\Inventory\Data\InventoryContext;

interface InventoryAllocator
{
    public function allocate(Stockable $stockable, int $quantity, InventoryContext $context): InventoryAllocation;
}
