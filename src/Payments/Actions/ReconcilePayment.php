<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Inventory\Actions\CommitReservation;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Contracts\PaymentGateway;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Drivers\FakePaymentGateway;
use EzEcommerce\Payments\Drivers\ManualPaymentGateway;
use EzEcommerce\Payments\Drivers\NullPaymentGateway;
use EzEcommerce\Payments\Drivers\PayPalPaymentGateway;
use EzEcommerce\Payments\Drivers\StripePaymentGateway;
use EzEcommerce\Payments\Drivers\TelrPaymentGateway;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;

final class ReconcilePayment
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly CommitReservation $commitReservation,
    ) {}

    public function execute(WebhookRequestData $request): GatewayWebhookEvent
    {
        $gateway = $this->resolveGateway($request->gateway);
        $event = $gateway->parseWebhook($request);

        $existing = ProcessedGatewayEvent::query()
            ->where('gateway', $request->gateway)
            ->where('external_id', $event->externalId)
            ->first();

        if ($existing !== null) {
            return $event;
        }

        ProcessedGatewayEvent::query()->create([
            'gateway' => $request->gateway,
            'external_id' => $event->externalId,
            'event_type' => $event->eventType,
            'processed_at' => $this->clock->now(),
        ]);

        $payment = $this->findPayment($event);
        if ($payment === null) {
            return $event;
        }

        $this->applyEvent($payment, $event);

        return $event;
    }

    private function findPayment(GatewayWebhookEvent $event): ?Payment
    {
        if ($event->paymentExternalId === null) {
            return null;
        }

        $attempt = PaymentAttempt::query()
            ->where('external_id', $event->paymentExternalId)
            ->first();

        if ($attempt !== null) {
            return $attempt->payment;
        }

        return Payment::query()
            ->whereHas('attempts', fn ($q) => $q->where('external_id', $event->paymentExternalId))
            ->first();
    }

    private function applyEvent(Payment $payment, GatewayWebhookEvent $event): void
    {
        if (str_contains($event->eventType, 'capture') || str_contains($event->eventType, 'paid')) {
            $amountMinor = $event->amountMinor ?? ($payment->amount_minor - $payment->captured_minor);

            PaymentTransaction::query()->create([
                'payment_id' => $payment->id,
                'type' => PaymentTransactionType::Capture,
                'amount_minor' => $amountMinor,
                'currency' => $event->currency ?? $payment->currency,
                'external_id' => $event->externalId,
                'status' => 'succeeded',
                'processed_at' => $this->clock->now(),
                'metadata' => $event->metadata,
            ]);

            $capturedMinor = $payment->captured_minor + $amountMinor;
            $status = $capturedMinor >= $payment->amount_minor
                ? PaymentStatus::Captured
                : PaymentStatus::PartiallyCaptured;

            $payment->update([
                'status' => $status,
                'captured_minor' => $capturedMinor,
            ]);

            $order = $payment->order;
            $this->recalculateOrderPaymentStatus->execute($order);
            $this->commitReservation->executeForOrder($order);

            if ($status === PaymentStatus::Captured) {
                Event::dispatch(new OrderPaid($order->id, $order->public_id, $payment->id));
            }
        }
    }

    private function resolveGateway(string $name): PaymentGateway
    {
        return match ($name) {
            'null' => app(NullPaymentGateway::class),
            'manual' => app(ManualPaymentGateway::class),
            'fake' => app(FakePaymentGateway::class),
            'stripe' => app(StripePaymentGateway::class),
            'paypal' => app(PayPalPaymentGateway::class),
            'telr' => app(TelrPaymentGateway::class),
            default => throw new InvalidArgumentException("Unknown payment gateway [{$name}]."),
        };
    }
}
