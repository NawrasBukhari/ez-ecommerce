<?php

namespace EzEcommerce\Returns\Actions;

use EzEcommerce\Core\Enums\ReturnStatus;
use EzEcommerce\Core\Events\Concerns\DispatchesCommerceWebhooks;
use EzEcommerce\Returns\Models\ReturnRequest;

final class ReceiveReturn
{
    use DispatchesCommerceWebhooks;

    public function execute(ReturnRequest $return): ReturnRequest
    {
        $return->update(['status' => ReturnStatus::Received]);

        $return = $return->fresh(['items']);

        $this->dispatchCommerceWebhook('return.received', [
            'return_id' => $return->public_id,
            'order_id' => $return->order?->public_id,
        ]);

        return $return;
    }
}
