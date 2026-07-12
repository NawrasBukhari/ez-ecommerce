<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Core\Idempotency\PurgeIdempotencyRecords;
use Illuminate\Console\Command;

class PurgeIdempotencyRecordsCommand extends Command
{
    protected $signature = 'commerce:purge-idempotency-records';

    protected $description = 'Delete expired idempotency records';

    public function handle(PurgeIdempotencyRecords $purgeIdempotencyRecords): int
    {
        $count = $purgeIdempotencyRecords->execute();
        $this->components->info("Purged {$count} idempotency record(s).");

        return self::SUCCESS;
    }
}
