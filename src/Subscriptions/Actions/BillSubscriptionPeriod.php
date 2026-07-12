<?php

namespace EzEcommerce\Subscriptions\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use EzEcommerce\Payments\Actions\CapturePayment;
use EzEcommerce\Payments\Actions\CreatePaymentSession;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Subscriptions\Models\Subscription;

final class BillSubscriptionPeriod
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CreatePaymentSession $createPaymentSession,
        private readonly CapturePayment $capturePayment,
    ) {}

    public function execute(Subscription $subscription): Order
    {
        $subscription->load(['plan', 'customer']);
        $plan = $subscription->plan;
        $amountMinor = (int) $plan->amount_minor;

        if ($amountMinor <= 0) {
            throw new \RuntimeException('Subscription plan has no billable amount.');
        }

        $order = Order::query()->create([
            'customer_id' => $subscription->customer_id,
            'status' => OrderStatus::PendingPayment,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'fulfillment_status' => FulfillmentStatus::Unfulfilled,
            'currency' => $plan->currency,
            'subtotal_minor' => $amountMinor,
            'discount_total_minor' => 0,
            'tax_total_minor' => 0,
            'shipping_total_minor' => 0,
            'fee_total_minor' => 0,
            'grand_total_minor' => $amountMinor,
            'payment_method' => $subscription->payment_method,
            'metadata' => [
                'subscription_id' => $subscription->id,
                'subscription_public_id' => $subscription->public_id,
                'billing_period_start' => $subscription->current_period_start?->format(\DateTimeInterface::ATOM),
                'billing_period_end' => $subscription->current_period_end?->format(\DateTimeInterface::ATOM),
            ],
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'quantity' => 1,
            'unit_price_minor' => $amountMinor,
            'line_subtotal_minor' => $amountMinor,
            'line_discount_minor' => 0,
            'line_tax_minor' => 0,
            'line_total_minor' => $amountMinor,
            'currency' => $plan->currency,
            'product_snapshot' => [
                'name' => $plan->name,
                'type' => 'subscription',
                'plan_id' => $plan->id,
            ],
        ]);

        $payment = Payment::query()->create([
            'order_id' => $order->id,
            'gateway' => $subscription->payment_method,
            'amount_minor' => $amountMinor,
            'currency' => $plan->currency,
            'status' => PaymentStatus::Created,
        ]);

        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'operation' => 'create_session',
            'idempotency_key' => 'sub_'.$subscription->public_id.'_'.$this->clock->now()->format('Y-m-d'),
            'status' => 'pending',
        ]);

        $session = $this->createPaymentSession->execute($payment, $attempt, $order);

        if ($session->status === PaymentStatus::Pending && $subscription->payment_method === 'manual') {
            $this->capturePayment->execute(
                $payment->fresh(),
                $attempt->fresh(),
                Money::fromMinor($amountMinor, $plan->currency),
            );
        }

        $subscription->update([
            'metadata' => array_merge($subscription->metadata?->toArray() ?? [], [
                'last_billed_order_id' => $order->id,
                'last_billed_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            ]),
        ]);

        return $order->fresh(['items', 'payments']);
    }
}
