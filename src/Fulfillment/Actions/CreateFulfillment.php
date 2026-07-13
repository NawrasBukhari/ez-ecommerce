<?php

namespace EzEcommerce\Fulfillment\Actions;

use EzEcommerce\Core\Exceptions\IdempotencyPayloadMismatchException;
use EzEcommerce\Fulfillment\Contracts\FulfillmentReleasePolicy;
use EzEcommerce\Fulfillment\Models\Fulfillment;
use EzEcommerce\Orders\Actions\RecalculateOrderFulfillmentStatus;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CreateFulfillment
{
    public function __construct(
        private readonly FulfillmentReleasePolicy $fulfillmentReleasePolicy,
        private readonly RecalculateOrderFulfillmentStatus $recalculateOrderFulfillmentStatus,
    ) {
    }

    public function execute(Order $order, OrderItem $item, int $quantity, ?string $idempotencyKey = null): Fulfillment
    {
        if (! $this->fulfillmentReleasePolicy->canFulfill($order)) {
            throw new RuntimeException('Order is not eligible for fulfillment.');
        }

        if ($quantity <= 0) {
            throw new RuntimeException('Invalid fulfillment quantity.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = Fulfillment::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                $this->assertPayloadMatches($existing, $order, $item, $quantity);

                return $existing;
            }
        }

        try {
            return DB::transaction(fn () => $this->create($order, $item, $quantity, $idempotencyKey));
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey === null || $idempotencyKey === '') {
                throw $e;
            }

            $existing = Fulfillment::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();
            $this->assertPayloadMatches($existing, $order, $item, $quantity);

            return $existing;
        }
    }

    private function create(Order $order, OrderItem $item, int $quantity, ?string $idempotencyKey): Fulfillment
    {
        $lockedItem = OrderItem::query()
            ->where('id', $item->id)
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->firstOrFail();

        $alreadyFulfilled = (int) Fulfillment::query()
            ->where('order_item_id', $lockedItem->id)
            ->sum('quantity');

        $remaining = $lockedItem->quantity - $alreadyFulfilled;
        if ($quantity > $remaining) {
            throw new RuntimeException("Cannot fulfill {$quantity}; only {$remaining} remaining.");
        }

        $fulfillment = Fulfillment::query()->create([
            'order_id' => $order->id,
            'order_item_id' => $lockedItem->id,
            'quantity' => $quantity,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->recalculateOrderFulfillmentStatus->execute($order);

        return $fulfillment;
    }

    private function assertPayloadMatches(Fulfillment $existing, Order $order, OrderItem $item, int $quantity): void
    {
        if ((int) $existing->order_id !== (int) $order->id
            || (int) $existing->order_item_id !== (int) $item->id
            || (int) $existing->quantity !== $quantity) {
            throw IdempotencyPayloadMismatchException::for('fulfillment', (string) $existing->idempotency_key);
        }
    }
}
