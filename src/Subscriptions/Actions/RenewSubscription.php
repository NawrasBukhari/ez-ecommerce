<?php

namespace EzEcommerce\Subscriptions\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\SubscriptionStatus;
use EzEcommerce\Subscriptions\Models\Subscription;

final class RenewSubscription
{
    public function __construct(
        private readonly Clock $clock,
        private readonly BillSubscriptionPeriod $billSubscriptionPeriod,
    ) {}

    public function execute(Subscription $subscription): Subscription
    {
        $subscription->load('plan');
        $now = $this->clock->now();

        if ($subscription->current_period_end > $now) {
            return $subscription;
        }

        if ((int) $subscription->plan->amount_minor > 0) {
            $this->billSubscriptionPeriod->execute($subscription);
        }

        $plan = $subscription->plan;
        $periodEnd = match ($plan->interval->value) {
            'day' => $now->modify("+{$plan->interval_count} days"),
            'week' => $now->modify("+{$plan->interval_count} weeks"),
            'month' => $now->modify("+{$plan->interval_count} months"),
            'year' => $now->modify("+{$plan->interval_count} years"),
            default => $now->modify('+1 month'),
        };

        $subscription->update([
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'status' => SubscriptionStatus::Active,
        ]);

        return $subscription->fresh();
    }
}
