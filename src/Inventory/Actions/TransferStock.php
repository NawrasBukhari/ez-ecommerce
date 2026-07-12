<?php

namespace EzEcommerce\Inventory\Actions;

use EzEcommerce\Catalog\Contracts\Stockable;
use EzEcommerce\Core\Support\MorphMap;
use EzEcommerce\Inventory\Models\InventoryBalance;
use EzEcommerce\Inventory\Models\InventoryMovement;
use EzEcommerce\Inventory\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class TransferStock
{
    public function execute(
        Warehouse $from,
        Warehouse $to,
        Stockable $stockable,
        int $quantity,
        string $idempotencyKey,
    ): void {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Transfer quantity must be positive.');
        }

        if ($from->id === $to->id) {
            throw new InvalidArgumentException('Source and destination warehouses must differ.');
        }

        if (! $stockable instanceof Model) {
            throw new InvalidArgumentException('Stockable must be an Eloquent model.');
        }

        DB::transaction(function () use ($from, $to, $stockable, $quantity, $idempotencyKey): void {
            $alias = MorphMap::aliasFor($stockable);
            $stockableId = $stockable->getKey();

            $fromBalance = InventoryBalance::query()
                ->where('warehouse_id', $from->id)
                ->where('stockable_type', $alias)
                ->where('stockable_id', $stockableId)
                ->lockForUpdate()
                ->firstOrFail();

            $available = $fromBalance->on_hand - $fromBalance->reserved;
            if ($available < $quantity) {
                throw new RuntimeException('Insufficient available stock to transfer.');
            }

            $fromBalance->update(['on_hand' => $fromBalance->on_hand - $quantity]);

            InventoryMovement::query()->create([
                'balance_id' => $fromBalance->id,
                'type' => 'transfer_out',
                'on_hand_delta' => -$quantity,
                'reserved_delta' => 0,
                'reference_type' => 'commerce_warehouse',
                'reference_id' => $to->id,
                'idempotency_scope' => 'inventory_transfer',
                'idempotency_key' => $idempotencyKey.'-out',
            ]);

            $toBalance = InventoryBalance::query()->firstOrCreate([
                'warehouse_id' => $to->id,
                'stockable_type' => $alias,
                'stockable_id' => $stockableId,
            ], [
                'on_hand' => 0,
                'reserved' => 0,
            ]);

            $toBalance = InventoryBalance::query()->lockForUpdate()->findOrFail($toBalance->id);
            $toBalance->update(['on_hand' => $toBalance->on_hand + $quantity]);

            InventoryMovement::query()->create([
                'balance_id' => $toBalance->id,
                'type' => 'transfer_in',
                'on_hand_delta' => $quantity,
                'reserved_delta' => 0,
                'reference_type' => 'commerce_warehouse',
                'reference_id' => $from->id,
                'idempotency_scope' => 'inventory_transfer',
                'idempotency_key' => $idempotencyKey.'-in',
            ]);
        });
    }
}
