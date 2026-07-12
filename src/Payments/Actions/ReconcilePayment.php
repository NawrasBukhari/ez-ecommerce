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
            ->where('external_event_id', $event->externalId)
            ->first();

        if ($existing !== null) {
            return $event;
        }

        ProcessedGatewayEvent::query()->create([
            'gateway' => $request->gateway,
            'external_event_id' => $event->externalId,
            'event_type' => $event->eventType,
            'payload' => json_decode($request->payload, true, 512, JSON_THROW_ON_ERROR),
            'processed_at' => $this->clock->now(),
        ]);

        $payment = $this->findPayment($event);
        if ($payment === null) {
            return $event;
        }

        $this->applyEvent($request->gateway, $payment, $event);

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

    private function applyEvent(string $gateway, Payment $payment, GatewayWebhookEvent $event): void
    {
        if (! $this->isSuccessfulCaptureEvent($gateway, $event->eventType)) {
            return;
        }

        if (PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Capture)
            ->where('external_id', $event->externalId)
            ->exists()) {
            return;
        }

        $amountMinor = $event->amountMinor ?? ($payment->amount_minor - $payment->captured_minor);
        if ($amountMinor <= 0) {
            return;
        }

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

    private function isSuccessfulCaptureEvent(string $gateway, string $eventType): bool
    {
        return match ($gateway) {
            'stripe' => in_array($eventType, [
                'payment_intent.succeeded',
                'charge.captured',
                'checkout.session.completed',
            ], true),
            'paypal' => in_array($eventType, [
                'PAYMENT.CAPTURE.COMPLETED',
                'CHECKOUT.ORDER.APPROVED',
                'PAYMENT.SALE.COMPLETED',
            ], true),
            'telr' => in_array($eventType, ['authorised', 'paid', 'success'], true),
            'fake' => $eventType === 'payment.captured',
            default => false,
        };
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
            'net_terms' => app(ManualPaymentGateway::class),
            default => throw new InvalidArgumentException("Unknown payment gateway [{$name}]."),
        };
    }
}
