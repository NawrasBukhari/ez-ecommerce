<?php

namespace EzEcommerce\Core\Idempotency;

use EzEcommerce\Core\Models\IdempotencyRecord;

final class PurgeIdempotencyRecords
{
    public function execute(): int
    {
        return IdempotencyRecord::query()
            ->where('expires_at', '<', now())
            ->delete();
    }
}
