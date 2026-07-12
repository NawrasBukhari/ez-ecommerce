<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Inventory\Exceptions\ReservationExpiredException;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ReconcilePayment
{
    public function __construct(
        private readonly Clock $clock,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly ApplyPaymentCapture $applyPaymentCapture,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly FinalizeAcceptedPayment $finalizeAcceptedPayment,
    ) {}

    public function execute(WebhookRequestData $request): GatewayWebhookEvent
    {
        $gateway = $this->gateways->for($request->gateway);
        $event = $gateway->parseWebhook($request);

        $existing = ProcessedGatewayEvent::query()
            ->where('gateway', $request->gateway)
            ->where('external_event_id', $event->externalId)
            ->first();

        if ($existing !== null && $existing->status === 'processed') {
            return $event;
        }

        if (! $this->isSuccessfulCaptureEvent($request->gateway, $event->eventType)) {
            return $event;
        }

        $payment = $this->findPayment($event);
        if ($payment === null) {
            return $event;
        }

        try {
            DB::transaction(function () use ($request, $event, $payment) {
                $record = ProcessedGatewayEvent::query()
                    ->where('gateway', $request->gateway)
                    ->where('external_event_id', $event->externalId)
                    ->lockForUpdate()
                    ->first();

                if ($record !== null && $record->status === 'processed') {
                    return;
                }

                if ($record === null) {
                    $record = ProcessedGatewayEvent::query()->create([
                        'gateway' => $request->gateway,
                        'external_event_id' => $event->externalId,
                        'event_type' => $event->eventType,
                        'payload' => json_decode($request->payload, true, 512, JSON_THROW_ON_ERROR),
                        'status' => 'processing',
                        'processed_at' => $this->clock->now(),
                    ]);
                } else {
                    $record->update(['status' => 'processing']);
                }

                $amountMinor = $event->amountMinor ?? ($payment->amount_minor - $payment->captured_minor);
                if ($amountMinor <= 0) {
                    $record->update(['status' => 'failed', 'last_error' => 'Invalid capture amount']);

                    return;
                }

                $payment = $this->applyPaymentCapture->execute(
                    $payment,
                    null,
                    $amountMinor,
                    $event->currency ?? $payment->currency,
                    $event->externalId,
                    $event->metadata,
                );

                $this->recalculateOrderPaymentStatus->execute($payment->order);

                try {
                    $this->finalizeAcceptedPayment->completeOrderAfterCapture($payment);
                } catch (ReservationExpiredException $e) {
                    $record->update([
                        'status' => 'failed',
                        'last_error' => $e->getMessage(),
                    ]);

                    return;
                }

                $record->update([
                    'status' => 'processed',
                    'processed_at' => $this->clock->now(),
                    'last_error' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return $event;
        }

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
}
