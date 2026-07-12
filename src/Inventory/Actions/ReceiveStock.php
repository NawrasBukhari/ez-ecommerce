<?php

namespace EzEcommerce\Inventory\Actions;

use EzEcommerce\Catalog\Contracts\Stockable;
use EzEcommerce\Core\Support\MorphMap;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\InventoryMovement;
use EzEcommerce\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class ReceiveStock
{
    public function executeForStockable(
        Warehouse $warehouse,
        Stockable $stockable,
        int $quantity,
        string $idempotencyKey,
        ?object $reference = null,
    ): InventoryBalance {
        if (! $stockable instanceof Model) {
            throw new InvalidArgumentException('Stockable must be an Eloquent model.');
        }

        $balance = InventoryBalance::query()->firstOrCreate([
            'warehouse_id' => $warehouse->id,
            'stockable_type' => MorphMap::aliasFor($stockable),
            'stockable_id' => $stockable->getKey(),
        ], [
            'on_hand' => 0,
            'reserved' => 0,
        ]);

        $this->execute($balance, $quantity, $idempotencyKey, $reference);

        return $balance->fresh();
    }

    public function execute(
        InventoryBalance $balance,
        int $quantity,
        string $idempotencyKey,
        ?object $reference = null,
    ): InventoryMovement {
        $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($balance->id);
        $balance->update(['on_hand' => $balance->on_hand + $quantity]);

        return InventoryMovement::query()->create([
            'balance_id' => $balance->id,
            'type' => 'receive',
            'on_hand_delta' => $quantity,
            'reserved_delta' => 0,
            'reference_type' => $reference !== null ? MorphMap::aliasFor($reference) : null,
            'reference_id' => $reference?->getKey(),
            'idempotency_scope' => 'inventory_receive',
            'idempotency_key' => $idempotencyKey,
        ]);
    }
}
