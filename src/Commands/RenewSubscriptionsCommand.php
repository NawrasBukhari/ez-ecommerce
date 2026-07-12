<?php

namespace EzEcommerce\Commands;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\SubscriptionStatus;
use EzEcommerce\Subscriptions\Actions\RenewSubscription;
use EzEcommerce\Subscriptions\Models\Subscription;
use Illuminate\Console\Command;

class RenewSubscriptionsCommand extends Command
{
    protected $signature = 'commerce:renew-subscriptions';

    protected $description = 'Renew subscriptions whose billing period has ended';

    public function handle(RenewSubscription $renewSubscription, Clock $clock): int
    {
        if (! config('ez-ecommerce.features.subscriptions', false)) {
            $this->components->warn('Subscriptions feature is disabled.');

            return self::SUCCESS;
        }

        $now = $clock->now();
        $count = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('current_period_end', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($renewSubscription, &$count): void {
                foreach ($subscriptions as $subscription) {
                    $renewSubscription->execute($subscription);
                    $count++;
                }
            });

        $this->components->info("Renewed {$count} subscription(s).");

        return self::SUCCESS;
    }
}
