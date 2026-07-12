<?php

namespace EzEcommerce\Returns\Actions;

use EzEcommerce\Returns\Models\ReturnItem;

final class MarkReturnedItemAsDamaged
{
    public function execute(ReturnItem $item): ReturnItem
    {
        $item->update(['damaged' => true, 'restock' => false]);

        return $item->fresh();
    }
}
