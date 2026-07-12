<?php

namespace EzEcommerce\Subscriptions\Actions;

use DateTimeImmutable;
use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\SubscriptionStatus;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Subscriptions\Models\Subscription;
use EzEcommerce\Subscriptions\Models\SubscriptionItem;
use EzEcommerce\Subscriptions\Models\SubscriptionPlan;

final class CreateSubscription
{
    public function __construct(
        private readonly Clock $clock,
    ) {
    }

    public function execute(
        Customer $customer,
        SubscriptionPlan $plan,
        string $paymentMethod = 'manual',
    ): Subscription {
        $now = $this->clock->now();
        $periodEnd = $this->calculatePeriodEnd($now, $plan->interval->value, $plan->interval_count);

        return Subscription::query()->create([
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'status' => SubscriptionStatus::Active,
            'payment_method' => $paymentMethod,
            'current_period_start' => $now,
            'current_period_end' => $periodEnd,
            'metadata' => [],
        ]);
    }

    public function addItem(Subscription $subscription, object $purchasable, int $quantity = 1): SubscriptionItem
    {
        return SubscriptionItem::query()->create([
            'subscription_id' => $subscription->id,
            'purchasable_type' => method_exists($purchasable, 'purchasableType')
                ? $purchasable->purchasableType()
                : $purchasable::class,
            'purchasable_id' => $purchasable->getKey(),
            'quantity' => $quantity,
        ]);
    }

    private function calculatePeriodEnd(DateTimeImmutable $start, string $interval, int $count): DateTimeImmutable
    {
        return match ($interval) {
            'day' => $start->modify("+{$count} days"),
            'week' => $start->modify("+{$count} weeks"),
            'month' => $start->modify("+{$count} months"),
            'year' => $start->modify("+{$count} years"),
            default => $start->modify('+1 month'),
        };
    }
}
