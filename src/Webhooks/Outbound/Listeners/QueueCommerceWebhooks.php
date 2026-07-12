<?php

namespace EzEcommerce\Webhooks\Outbound\Listeners;

use EzEcommerce\Core\Events\Concerns\DispatchesCommerceWebhooks;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Events\OrderPlaced;

final class QueueCommerceWebhooks
{
    use DispatchesCommerceWebhooks;

    public function handleOrderPlaced(OrderPlaced $event): void
    {
        $this->dispatchCommerceWebhook('order.placed', [
            'order_id' => $event->orderId,
            'order_public_id' => $event->orderPublicId,
        ]);
    }

    public function handleOrderPaid(OrderPaid $event): void
    {
        $this->dispatchCommerceWebhook('order.paid', [
            'order_id' => $event->orderId,
            'order_public_id' => $event->orderPublicId,
            'payment_id' => $event->paymentId,
        ]);
    }
}
