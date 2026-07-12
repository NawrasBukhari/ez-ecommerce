<?php

namespace EzEcommerce\Returns\Actions;

use EzEcommerce\Core\Enums\ReturnStatus;
use EzEcommerce\Returns\Models\ReturnRequest;

final class ReceiveReturn
{
    public function execute(ReturnRequest $return): ReturnRequest
    {
        $return->update(['status' => ReturnStatus::Received]);

        return $return->fresh(['items']);
    }
}
