<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Enums\PaymentTransactionType;
use EzEcommerce\Inventory\Exceptions\InventoryCommitException;
use EzEcommerce\Inventory\Exceptions\ReservationExpiredException;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\Models\PaymentTransaction;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use EzEcommerce\Webhooks\Inbound\Exceptions\InboundWebhookConflictException;
use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ReconcilePayment
{
    public function __construct(
        private readonly Clock $clock,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly ApplyPaymentCapture $applyPaymentCapture,
        private readonly ApplyPaymentReversal $applyPaymentReversal,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly FinalizeAcceptedPayment $finalizeAcceptedPayment,
        private readonly RecordInventoryFinalizationFailure $recordInventoryFinalizationFailure,
    ) {
    }

    public function execute(WebhookRequestData $request): GatewayWebhookEvent
    {
        $gateway = $this->gateways->for($request->gateway);
        $event = $gateway->parseWebhook($request);

        $existing = ProcessedGatewayEvent::query()
            ->where('gateway', $request->gateway)
            ->where('external_event_id', $event->eventId)
            ->first();

        if ($existing !== null && in_array($existing->status, ['processed'], true)) {
            return $event;
        }

        $isCapture = $this->isSuccessfulCaptureEvent($request->gateway, $event->eventType);
        $isAuthorization = $this->isAuthorizationEvent($request->gateway, $event->eventType);
        $isFailure = $this->isFailureEvent($request->gateway, $event->eventType);
        $isPendingCapture = $this->isPendingCaptureEvent($request->gateway, $event->eventType);
        $isReversal = $this->isReversalEvent($request->gateway, $event->eventType);

        if (! $isCapture && ! $isAuthorization && ! $isFailure && ! $isPendingCapture && ! $isReversal) {
            return $event;
        }

        try {
            DB::transaction(function () use ($request, $event, $isAuthorization, $isFailure, $isPendingCapture, $isReversal) {
                $record = ProcessedGatewayEvent::query()
                    ->where('gateway', $request->gateway)
                    ->where('external_event_id', $event->eventId)
                    ->lockForUpdate()
                    ->first();

                if ($record !== null && in_array($record->status, ['processed'], true)) {
                    return;
                }

                if ($record === null) {
                    $record = ProcessedGatewayEvent::query()->create([
                        'gateway' => $request->gateway,
                        'external_event_id' => $event->eventId,
                        'event_type' => $event->eventType,
                        'payload' => json_decode($request->payload, true, 512, JSON_THROW_ON_ERROR),
                        'status' => 'processing',
                        'processed_at' => $this->clock->now(),
                    ]);
                } else {
                    $record->update(['status' => 'processing']);
                }

                $payment = $this->findPayment($event);
                if ($payment === null) {
                    $record->update(['status' => 'unmatched', 'processed_at' => $this->clock->now()]);

                    return;
                }

                // Reversal has precedence over failure/pending: a provider clawback
                // is a first-class ledger event, not a generic capture failure. But
                // a reversal arriving before the capture completion cannot be
                // applied (no captured ledger to reverse) — defer it so a later
                // completion webhook can replay it. Marking it `processed` would
                // permanently lose the reversal under out-of-order delivery.
                if ($isReversal) {
                    $capturedMinor = $this->sumCaptured($payment);
                    if ($capturedMinor <= 0 && ! in_array($payment->status, [
                        PaymentStatus::Captured,
                        PaymentStatus::PartiallyCaptured,
                        PaymentStatus::Reversed,
                    ], true)) {
                        $record->update([
                            'status' => 'deferred',
                            'payload' => array_merge(
                                (array) ($record->payload ?? []),
                                [
                                    'payment_reference' => $event->paymentReference,
                                    'transaction_reference' => $event->transactionReference ?? $event->paymentReference,
                                    'amount_minor' => $event->amountMinor ?? $payment->captured_minor,
                                    'currency' => $event->currency ?? $payment->currency,
                                    'provider_status' => $event->providerStatus,
                                    'metadata' => $event->metadata,
                                ],
                            ),
                        ]);

                        return;
                    }

                    $this->applyReversal($payment, $event);
                    $record->update(['status' => 'processed', 'processed_at' => $this->clock->now(), 'last_error' => null]);

                    return;
                }

                if ($isFailure) {
                    $this->applyFailureTransition($payment, $event);
                    $record->update(['status' => 'processed', 'processed_at' => $this->clock->now(), 'last_error' => null]);

                    return;
                }

                if ($isPendingCapture) {
                    $this->applyPendingCaptureTransition($payment, $event);
                    $record->update(['status' => 'processed', 'processed_at' => $this->clock->now(), 'last_error' => null]);

                    return;
                }

                if ($isAuthorization) {
                    $this->applyAuthorization($payment, $event);
                    $record->update([
                        'status' => 'processed',
                        'processed_at' => $this->clock->now(),
                        'last_error' => null,
                    ]);

                    return;
                }

                $amountMinor = $event->amountMinor ?? ($payment->amount_minor - $payment->captured_minor);
                if ($amountMinor <= 0) {
                    $record->update(['status' => 'failed', 'last_error' => 'Invalid capture amount']);

                    return;
                }

                $transactionReference = $event->transactionReference ?? $event->paymentReference;

                // A delayed completion webhook arriving after a reversal must not
                // restore the payment to Captured. Reversal is terminal-monotonic.
                $currentStatus = Payment::query()
                    ->where('id', $payment->id)
                    ->lockForUpdate()
                    ->value('status');
                if ($currentStatus === PaymentStatus::Reversed->value) {
                    $record->update(['status' => 'processed', 'processed_at' => $this->clock->now(), 'last_error' => null]);

                    return;
                }

                // Resolve the original pending capture attempt using the provider
                // reference so it does not stay pending forever after completion.
                $captureAttempt = $this->findPendingCaptureAttempt($payment, $transactionReference);

                $payment = $this->applyPaymentCapture->execute(
                    $payment,
                    $captureAttempt,
                    $amountMinor,
                    $event->currency ?? $payment->currency,
                    $transactionReference,
                    $event->metadata,
                );

                $this->recalculateOrderPaymentStatus->execute($payment->order);

                try {
                    $this->finalizeAcceptedPayment->completeOrderAfterCapture($payment);
                } catch (ReservationExpiredException|InventoryCommitException $e) {
                    $this->recordInventoryFinalizationFailure->execute($payment, null, $e->getMessage());

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

                // After a successful capture completion, replay any deferred
                // reversal webhook that arrived before the capture existed.
                $this->replayDeferredReversals($request->gateway, $payment, $record);
            });
        } catch (UniqueConstraintViolationException) {
            $record = ProcessedGatewayEvent::query()
                ->where('gateway', $request->gateway)
                ->where('external_event_id', $event->eventId)
                ->first();

            if ($record !== null && in_array($record->status, ['processed', 'unmatched', 'deferred'], true)) {
                return $event;
            }

            throw new InboundWebhookConflictException($record?->status);
        }

        return $event;
    }

    private function findPayment(GatewayWebhookEvent $event): ?Payment
    {
        $reference = $event->paymentReference;
        if ($reference === null) {
            return null;
        }

        $attempt = PaymentAttempt::query()
            ->where('external_id', $reference)
            ->first();

        if ($attempt !== null) {
            return $attempt->payment;
        }

        $payment = Payment::query()->where('public_id', $reference)->first();
        if ($payment !== null) {
            return $payment;
        }

        return Payment::query()
            ->whereHas('order', fn ($query) => $query->where('public_id', $reference))
            ->first();
    }

    private function isSuccessfulCaptureEvent(string $gateway, string $eventType): bool
    {
        return match ($gateway) {
            'stripe' => in_array($eventType, [
                'payment_intent.succeeded',
                'charge.captured',
            ], true),
            'paypal' => in_array($eventType, [
                'PAYMENT.CAPTURE.COMPLETED',
                'PAYMENT.SALE.COMPLETED',
            ], true),
            'telr' => in_array($eventType, ['authorised', 'paid', 'success'], true),
            'fake' => $eventType === 'payment.captured',
            default => false,
        };
    }

    private function isAuthorizationEvent(string $gateway, string $eventType): bool
    {
        return match ($gateway) {
            'stripe' => $eventType === 'payment_intent.amount_capturable_updated',
            'fake' => $eventType === 'payment_intent.amount_capturable_updated',
            default => false,
        };
    }

    private function isFailureEvent(string $gateway, string $eventType): bool
    {
        return match ($gateway) {
            'stripe' => in_array($eventType, [
                'payment_intent.payment_failed',
                'payment_intent.canceled',
            ], true),
            'paypal' => in_array($eventType, [
                'PAYMENT.CAPTURE.DECLINED',
            ], true),
            'fake' => in_array($eventType, [
                'payment_intent.payment_failed',
                'payment_intent.canceled',
            ], true),
            default => false,
        };
    }

    private function isReversalEvent(string $gateway, string $eventType): bool
    {
        return match ($gateway) {
            'paypal' => in_array($eventType, [
                'PAYMENT.CAPTURE.REVERSED',
            ], true),
            'fake' => in_array($eventType, ['payment.reversed'], true),
            default => false,
        };
    }

    private function applyReversal(Payment $payment, GatewayWebhookEvent $event): void
    {
        $amountMinor = $event->amountMinor ?? $payment->captured_minor;
        if ($amountMinor <= 0) {
            $amountMinor = $payment->captured_minor;
        }

        $this->applyPaymentReversal->execute(
            $payment,
            $event->transactionReference ?? $event->paymentReference,
            $amountMinor,
            $event->currency ?? $payment->currency,
            $event->metadata,
        );
    }

    private function applyFailureTransition(Payment $payment, GatewayWebhookEvent $event): void
    {
        $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

        // ponytail: Failure transitions are monotonic — never regress terminal states.
        // Ceiling: a late failure webhook for an already-captured payment is ignored; the provider
        // object should be retrieved before deciding on ambiguous cases.
        // Upgrade: fetch the latest provider object for ambiguous states.
        $terminal = [
            PaymentStatus::Captured,
            PaymentStatus::PartiallyCaptured,
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Cancelled,
            PaymentStatus::Failed,
            PaymentStatus::Reversed,
        ];

        if (in_array($locked->status, $terminal, true)) {
            return;
        }

        $locked->update(['status' => PaymentStatus::Failed]);
        $this->recalculateOrderPaymentStatus->execute($locked->order);
    }

    private function isPendingCaptureEvent(string $gateway, string $eventType): bool
    {
        return match ($gateway) {
            'paypal' => in_array($eventType, [
                'PAYMENT.CAPTURE.PENDING',
            ], true),
            default => false,
        };
    }

    private function applyPendingCaptureTransition(Payment $payment, GatewayWebhookEvent $event): void
    {
        $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

        // Pending is a pre-capture state; never regress a captured/terminal payment.
        $terminal = [
            PaymentStatus::Captured,
            PaymentStatus::PartiallyCaptured,
            PaymentStatus::Refunded,
            PaymentStatus::PartiallyRefunded,
            PaymentStatus::Cancelled,
            PaymentStatus::Failed,
            PaymentStatus::Reversed,
        ];

        if (in_array($locked->status, $terminal, true)) {
            return;
        }

        $locked->update(['status' => PaymentStatus::Pending]);
        $this->recalculateOrderPaymentStatus->execute($locked->order);
    }

    private function applyAuthorization(Payment $payment, GatewayWebhookEvent $event): Payment
    {
        $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

        if ($locked->status === PaymentStatus::Authorized) {
            return $locked->fresh();
        }

        // ponytail: Authorization is monotonic — only advance from pre-authorization states.
        // Ceiling: terminal states (Captured/Refunded/Cancelled/Failed) are never regressed by a
        // late or out-of-order auth webhook; Stripe does not guarantee delivery order.
        // Upgrade: retrieve the latest provider object before deciding on ambiguous states.
        $allowedSources = [
            PaymentStatus::Created,
            PaymentStatus::Pending,
            PaymentStatus::RequiresAction,
        ];

        if (! in_array($locked->status, $allowedSources, true)) {
            return $locked->fresh();
        }

        // Never reactivate payment processing for an already-cancelled order.
        $order = $locked->order;
        if ($order !== null && $order->status === \EzEcommerce\Core\Enums\OrderStatus::Cancelled) {
            return $locked->fresh();
        }

        $externalId = $event->transactionReference ?? $event->paymentReference;

        if ($externalId !== null && PaymentTransaction::query()
            ->where('payment_id', $locked->id)
            ->where('type', PaymentTransactionType::Authorization)
            ->where('external_id', $externalId)
            ->exists()) {
            $locked->update(['status' => PaymentStatus::Authorized]);

            return $locked->fresh();
        }

        PaymentTransaction::query()->create([
            'payment_id' => $locked->id,
            'type' => PaymentTransactionType::Authorization,
            'amount_minor' => $event->amountMinor ?? $locked->amount_minor,
            'currency' => $event->currency ?? $locked->currency,
            'external_id' => $externalId,
            'status' => 'succeeded',
            'processed_at' => $this->clock->now(),
        ]);

        $locked->update([
            'status' => PaymentStatus::Authorized,
            'authorized_minor' => $event->amountMinor ?? $locked->amount_minor,
        ]);

        $this->recalculateOrderPaymentStatus->execute($locked->order);

        return $locked->fresh();
    }

    private function sumCaptured(Payment $payment): int
    {
        return (int) PaymentTransaction::query()
            ->where('payment_id', $payment->id)
            ->where('type', PaymentTransactionType::Capture)
            ->where('status', 'succeeded')
            ->sum('amount_minor');
    }

    /**
     * Find the original pending capture attempt by provider reference so a
     * completion webhook can resolve it to succeeded instead of leaving it
     * pending forever.
     */
    private function findPendingCaptureAttempt(Payment $payment, ?string $reference): ?PaymentAttempt
    {
        if ($reference === null) {
            return null;
        }

        return PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->where('operation', 'capture')
            ->where('status', 'pending')
            ->where(function ($query) use ($reference) {
                $query->where('external_id', $reference)
                    ->orWhereNull('external_id');
            })
            ->orderBy('external_id', 'desc')
            ->first();
    }

    /**
     * After a capture completion, find deferred reversal inbox records for this
     * gateway and payment and replay them. Each replay applies the reversal to
     * the now-captured payment and marks the inbox record processed.
     */
    private function replayDeferredReversals(string $gateway, Payment $payment, ProcessedGatewayEvent $currentRecord): void
    {
        $deferred = ProcessedGatewayEvent::query()
            ->where('gateway', $gateway)
            ->where('status', 'deferred')
            ->where('id', '!=', $currentRecord->id)
            ->get()
            ->filter(fn (ProcessedGatewayEvent $record) => $this->findPaymentFromPayload($record) === $payment->id);

        foreach ($deferred as $record) {
            $payload = (array) ($record->payload ?? []);

            $event = new GatewayWebhookEvent(
                eventType: $record->event_type,
                eventId: $record->external_event_id,
                paymentReference: $payload['payment_reference'] ?? $payment->public_id,
                transactionReference: $payload['transaction_reference'] ?? null,
                amountMinor: $payload['amount_minor'] ?? $payment->captured_minor,
                currency: $payload['currency'] ?? $payment->currency,
                providerStatus: $payload['provider_status'] ?? null,
                metadata: $payload['metadata'] ?? [],
            );

            $this->applyReversal($payment, $event);
            $record->update(['status' => 'processed', 'processed_at' => $this->clock->now(), 'last_error' => null]);
        }
    }

    /**
     * Resolve the payment id from a stored webhook payload, matching the same
     * lookup logic as findPayment but returning an id for cheap comparison.
     */
    private function findPaymentFromPayload(ProcessedGatewayEvent $record): ?int
    {
        $payload = (array) ($record->payload ?? []);

        $reference = $payload['payment_reference'] ?? null;
        if (! is_string($reference) || $reference === '') {
            return null;
        }

        $attempt = PaymentAttempt::query()->where('external_id', $reference)->first();
        if ($attempt !== null) {
            return $attempt->payment_id;
        }

        $payment = Payment::query()->where('public_id', $reference)->first();
        if ($payment !== null) {
            return $payment->id;
        }

        $payment = Payment::query()
            ->whereHas('order', fn ($query) => $query->where('public_id', $reference))
            ->first();

        return $payment?->id;
    }
}
