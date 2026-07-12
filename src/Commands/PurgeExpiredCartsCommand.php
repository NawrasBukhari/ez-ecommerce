<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Cart\Actions\PurgeExpiredCarts;
use Illuminate\Console\Command;

class PurgeExpiredCartsCommand extends Command
{
    protected $signature = 'commerce:purge-expired-carts';

    protected $description = 'Mark expired guest carts as expired';

    public function handle(PurgeExpiredCarts $purgeExpiredCarts): int
    {
        $count = $purgeExpiredCarts->execute();
        $this->components->info("Marked {$count} cart(s) as expired.");

        return self::SUCCESS;
    }
}
