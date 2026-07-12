<?php

namespace EzEcommerce\Marketplace\Actions;

use EzEcommerce\Core\Enums\VendorCommissionStatus;
use EzEcommerce\Marketplace\Models\Vendor;
use EzEcommerce\Marketplace\Models\VendorCommission;
use EzEcommerce\Orders\Models\Order;

final class RecordVendorCommissions
{
    public function execute(Order $order): Order
    {
        if (! config('ez-ecommerce.features.marketplace', false)) {
            return $order;
        }

        $order->load('items');

        foreach ($order->items as $item) {
            $vendorId = $item->product_snapshot['vendor_id'] ?? null;
            if ($vendorId === null) {
                continue;
            }

            $vendor = Vendor::query()->find($vendorId);
            if ($vendor === null) {
                continue;
            }

            $commissionMinor = (int) round($item->total_minor * (float) $vendor->commission_rate);

            VendorCommission::query()->create([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'vendor_id' => $vendor->id,
                'amount_minor' => $commissionMinor,
                'currency' => $order->currency,
                'status' => VendorCommissionStatus::Pending,
            ]);
        }

        return $order->fresh();
    }
}
