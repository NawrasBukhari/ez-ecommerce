<?php

namespace EzEcommerce\Orders\Actions;

use EzEcommerce\Core\Enums\AdjustmentOrigin;
use EzEcommerce\Core\Enums\AdjustmentType;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderAdjustment;

final class AllocateLineDiscounts
{
    public function execute(Order $order): Order
    {
        $order->load(['items', 'adjustments']);

        $cartDiscountMinor = abs($order->adjustments
            ->where('type', AdjustmentType::Discount)
            ->whereNull('order_item_id')
            ->sum('amount_minor'));

        if ($cartDiscountMinor === 0) {
            return $order;
        }

        $lineSubtotals = $order->items->map(
            fn ($item) => Money::fromMinor($item->subtotal_minor, $order->currency)
        )->all();

        $allocations = Money::allocate(
            Money::fromMinor($cartDiscountMinor, $order->currency),
            $lineSubtotals,
        );

        foreach ($order->items->values() as $index => $item) {
            $allocated = $allocations[$index]->minorAmount;
            $item->update([
                'discount_minor' => $allocated,
                'total_minor' => $item->subtotal_minor - $allocated,
            ]);

            OrderAdjustment::query()
                ->where('order_id', $order->id)
                ->where('order_item_id', $item->id)
                ->where('type', AdjustmentType::Discount)
                ->where('origin', AdjustmentOrigin::Promotion)
                ->delete();

            if ($allocated > 0) {
                OrderAdjustment::query()->create([
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'type' => AdjustmentType::Discount,
                    'label' => 'Line discount allocation',
                    'amount_minor' => -$allocated,
                    'currency' => $order->currency,
                    'origin' => AdjustmentOrigin::Promotion,
                    'affects_total' => false,
                ]);
            }
        }

        return $order->fresh(['items', 'adjustments']);
    }
}
