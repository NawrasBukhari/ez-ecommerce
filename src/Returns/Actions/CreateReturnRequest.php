<?php

namespace EzEcommerce\Returns\Actions;

use EzEcommerce\Core\Enums\ReturnStatus;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Returns\Models\ReturnItem;
use EzEcommerce\Returns\Models\ReturnRequest;

final class CreateReturnRequest
{
    /**
     * @param  list<array{order_item_id: int, quantity: int, restock?: bool}>  $lines
     */
    public function execute(Order $order, array $lines, ?string $reason = null): ReturnRequest
    {
        $return = ReturnRequest::query()->create([
            'order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'status' => ReturnStatus::Requested,
            'reason' => $reason,
        ]);

        foreach ($lines as $line) {
            ReturnItem::query()->create([
                'return_id' => $return->id,
                'order_item_id' => $line['order_item_id'],
                'quantity' => $line['quantity'],
                'restock' => $line['restock'] ?? false,
                'damaged' => false,
            ]);
        }

        return $return->fresh(['items']);
    }
}
