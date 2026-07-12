<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Inventory\Actions\ReleaseExpiredReservations;
use Illuminate\Console\Command;

class ReleaseExpiredReservationsCommand extends Command
{
    protected $signature = 'commerce:release-expired-reservations';

    protected $description = 'Release inventory reservations that have expired';

    public function handle(ReleaseExpiredReservations $releaseExpiredReservations): int
    {
        $released = $releaseExpiredReservations->execute();
        $this->components->info("Released {$released} expired reservation(s).");

        return self::SUCCESS;
    }
}
