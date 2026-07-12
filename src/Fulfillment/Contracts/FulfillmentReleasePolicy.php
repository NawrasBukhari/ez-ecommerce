<?php

namespace EzEcommerce\Fulfillment\Contracts;

use EzEcommerce\Orders\Models\Order;

interface FulfillmentReleasePolicy
{
    public function canFulfill(Order $order): bool;
}
