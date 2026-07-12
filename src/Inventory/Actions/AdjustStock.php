<?php

namespace EzEcommerce\Inventory\Actions;

use EzEcommerce\Catalog\Contracts\Stockable;
use EzEcommerce\Core\Support\MorphMap;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RuntimeException;

final class AdjustStock
{
    public function __construct(
        private readonly ReceiveStock $receiveStock,
    ) {
    }

    public function execute(
        Warehouse $warehouse,
        Stockable $stockable,
        int $delta,
        string $idempotencyKey,
    ): InventoryBalance {
        if ($delta === 0) {
            throw new InvalidArgumentException('Adjustment delta cannot be zero.');
        }

        if (! $stockable instanceof Model) {
            throw new InvalidArgumentException('Stockable must be an Eloquent model.');
        }

        if ($delta > 0) {
            return $this->receiveStock->executeForStockable(
                $warehouse,
                $stockable,
                $delta,
                $idempotencyKey,
            );
        }

        $balance = InventoryBalance::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('stockable_type', MorphMap::aliasFor($stockable))
            ->where('stockable_id', $stockable->getKey())
            ->firstOrFail();

        $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($balance->id);
        $available = $balance->on_hand - $balance->reserved;

        if ($available < abs($delta)) {
            throw new RuntimeException('Adjustment would reduce stock below reserved quantity.');
        }

        $this->receiveStock->execute($balance, $delta, $idempotencyKey);

        return $balance->fresh();
    }
}
