<?php

namespace EzEcommerce\Inventory;

use EzEcommerce\Catalog\Contracts\Stockable;
use EzEcommerce\Core\Support\MorphMap;
use EzEcommerce\Inventory\Contracts\InventoryAllocator;
use EzEcommerce\Inventory\Data\InventoryAllocation;
use EzEcommerce\Inventory\Data\InventoryContext;
use EzEcommerce\Inventory\Data\WarehouseAllocation;
use EzEcommerce\Inventory\Models\InventoryBalance;
use InvalidArgumentException;

final class DefaultWarehouseAllocator implements InventoryAllocator
{
    public function allocate(Stockable $stockable, int $quantity, InventoryContext $context): InventoryAllocation
    {
        $warehouseId = config('ez-ecommerce.inventory.default_warehouse_id');
        if ($warehouseId === null) {
            throw new InvalidArgumentException('Default warehouse is not configured.');
        }

        $balance = InventoryBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('stockable_type', MorphMap::aliasFor($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->first();

        if ($balance === null) {
            throw new InvalidArgumentException('No inventory balance found for stockable.');
        }

        return new InventoryAllocation([
            new WarehouseAllocation($warehouseId, $balance->id, $quantity),
        ]);
    }
}
