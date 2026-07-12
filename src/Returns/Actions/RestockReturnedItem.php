<?php

namespace EzEcommerce\Returns\Actions;

use EzEcommerce\Catalog\Contracts\Stockable;
use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\Inventory\Actions\ReceiveStock;
use EzEcommerce\Inventory\Models\Warehouse;
use EzEcommerce\Returns\Models\ReturnItem;

final class RestockReturnedItem
{
    public function __construct(
        private readonly ReceiveStock $receiveStock,
    ) {
    }

    public function execute(ReturnItem $item, Warehouse $warehouse, string $idempotencyKey): ReturnItem
    {
        $orderItem = $item->orderItem;
        $snapshot = $orderItem->product_snapshot?->toArray() ?? [];
        $variantId = $snapshot['purchasable_id'] ?? null;

        if ($variantId !== null) {
            $variant = ProductVariant::query()->find($variantId);
            if ($variant instanceof Stockable) {
                $this->receiveStock->executeForStockable(
                    $warehouse,
                    $variant,
                    $item->quantity,
                    $idempotencyKey,
                    $item,
                );
            }
        }

        $item->update(['restock' => true, 'damaged' => false]);

        return $item->fresh();
    }
}
