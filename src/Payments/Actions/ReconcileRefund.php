<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\RefundStatus;
use EzEcommerce\Payments\Data\GatewayWebhookEvent;
use EzEcommerce\Payments\Data\WebhookRequestData;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use EzEcommerce\Refunds\Actions\RefundPayment;
use EzEcommerce\Refunds\Models\Refund;
use EzEcommerce\Refunds\Policies\RefundTransitionPolicy;
use EzEcommerce\Webhooks\Inbound\Exceptions\InboundWebhookConflictException;
use EzEcommerce\Webhooks\Inbound\Models\ProcessedGatewayEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ReconcileRefund
{
    public function __construct(
        private readonly Clock $clock,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly RefundPayment $refundPayment,
        private readonly RefundTransitionPolicy $refundTransitionPolicy,
    ) {
    }

    public function isRefundEvent(string $gateway, string $eventType): bool
    {
        return match ($gateway) {
            // charge.refunded is intentionally excluded: it correlates weakly to a
            // specific refund object and carries no per-refund metadata. The
            // refund.* events are the primary refund-state signal.
            'stripe' => in_array($eventType, [
                'refund.created',
                'refund.updated',
                'refund.failed',
            ], true),
            'paypal' => in_array($eventType, [
                'PAYMENT.CAPTURE.REFUNDED',
                'PAYMENT.REFUND.FAILED',
                'PAYMENT.REFUND.PENDING',
            ], true),
            'fake' => in_array($eventType, ['payment.refunded', 'refund.updated'], true),
            default => false,
        };
    }

    public function execute(WebhookRequestData $request): GatewayWebhookEvent
    {
        $gateway = $this->gateways->for($request->gateway);
        $event = $gateway->parseWebhook($request);

        if (! $this->isRefundEvent($request->gateway, $event->eventType)) {
            return $event;
        }

        $existing = ProcessedGatewayEvent::query()
            ->where('gateway', $request->gateway)
            ->where('external_event_id', $event->eventId)
            ->first();

        if ($existing !== null && in_array($existing->status, ['processed'], true)) {
            return $event;
        }

        $providerStatus = $event->providerStatus;

        try {
            DB::transaction(function () use ($request, $event, $providerStatus) {
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

                $attempt = $this->findRefundAttempt($event);
                if ($attempt === null) {
                    $record->update(['status' => 'unmatched', 'processed_at' => $this->clock->now()]);

                    return;
                }

                $refundId = $this->attemptMetadata($attempt)['refund_id'] ?? null;
                if ($refundId === null) {
                    $record->update(['status' => 'unmatched', 'processed_at' => $this->clock->now()]);

                    return;
                }

                $refund = Refund::query()->find((int) $refundId);
                if ($refund === null) {
                    $record->update(['status' => 'unmatched', 'processed_at' => $this->clock->now()]);

                    return;
                }

                $payment = $attempt->payment;
                if ($payment === null) {
                    $record->update(['status' => 'unmatched', 'processed_at' => $this->clock->now()]);

                    return;
                }

                $outcome = $this->classifyRefundOutcome($request->gateway, $event->eventType, $providerStatus);

                if ($outcome === null) {
                    $record->update(['status' => 'unmatched', 'processed_at' => $this->clock->now()]);

                    return;
                }

                if ($outcome === 'succeeded') {
                    if ($refund->status === RefundStatus::Succeeded) {
                        $record->update(['status' => 'processed', 'processed_at' => $this->clock->now()]);

                        return;
                    }

                    $amountMinor = $event->amountMinor ?? $refund->amount_minor;
                    $this->refundPayment->finalizeProviderRefund(
                        $payment,
                        $refund,
                        $attempt,
                        new \EzEcommerce\Payments\Data\RefundResult(
                            success: true,
                            status: RefundStatus::Succeeded,
                            amount: \EzEcommerce\Core\Money\Money::fromMinor($amountMinor, $refund->currency),
                            externalId: $event->transactionReference ?? $event->paymentReference,
                        ),
                    );
                } elseif ($outcome === 'pending') {
                    $lockedRefund = Refund::query()->lockForUpdate()->find($refund->id);
                    $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->find($attempt->id);
                    if ($lockedRefund !== null
                        && $lockedAttempt !== null
                        && $this->refundTransitionPolicy->canTransition($lockedRefund->status, RefundStatus::Pending)) {
                        $lockedRefund->update(['status' => RefundStatus::Pending]);
                        $lockedAttempt->update(['status' => 'pending']);
                    }
                } elseif ($outcome === 'failed') {
                    $lockedRefund = Refund::query()->lockForUpdate()->find($refund->id);
                    $lockedAttempt = PaymentAttempt::query()->lockForUpdate()->find($attempt->id);
                    if ($lockedRefund !== null
                        && $lockedAttempt !== null
                        && $this->refundTransitionPolicy->canTransition($lockedRefund->status, RefundStatus::Failed)) {
                        $lockedRefund->update(['status' => RefundStatus::Failed]);
                        $lockedAttempt->update(['status' => 'failed']);
                    }
                }

                $record->update([
                    'status' => 'processed',
                    'processed_at' => $this->clock->now(),
                    'last_error' => null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $record = ProcessedGatewayEvent::query()
                ->where('gateway', $request->gateway)
                ->where('external_event_id', $event->eventId)
                ->first();

            if ($record !== null && in_array($record->status, ['processed', 'unmatched'], true)) {
                return $event;
            }

            throw new InboundWebhookConflictException($record?->status);
        }

        return $event;
    }

    private function findRefundAttempt(GatewayWebhookEvent $event): ?PaymentAttempt
    {
        // Refund-object events (Stripe refund.created/updated/failed) carry local
        // identifiers in metadata; prefer those for precise per-refund correlation.
        $metadata = $event->metadata;
        if (! empty($metadata)) {
            $refundPublicId = $metadata['refund_public_id'] ?? null;
            if (is_string($refundPublicId) && $refundPublicId !== '') {
                $refund = Refund::query()->where('public_id', $refundPublicId)->first();
                if ($refund !== null) {
                    $attempt = PaymentAttempt::query()
                        ->where('operation', 'refund')
                        ->where('external_id', $event->transactionReference)
                        ->first();
                    if ($attempt !== null) {
                        return $attempt;
                    }
                }
            }

            $attemptPublicId = $metadata['payment_attempt_public_id'] ?? null;
            if (is_string($attemptPublicId) && $attemptPublicId !== '') {
                $attempt = PaymentAttempt::query()
                    ->where('public_id', $attemptPublicId)
                    ->where('operation', 'refund')
                    ->first();
                if ($attempt !== null) {
                    return $attempt;
                }
            }
        }

        foreach ([$event->transactionReference, $event->paymentReference] as $reference) {
            if (! is_string($reference) || $reference === '') {
                continue;
            }

            $attempt = PaymentAttempt::query()
                ->where('operation', 'refund')
                ->where('external_id', $reference)
                ->first();

            if ($attempt !== null) {
                return $attempt;
            }

            $attempt = PaymentAttempt::query()
                ->where('operation', 'refund')
                ->where('idempotency_key', $reference)
                ->first();

            if ($attempt !== null) {
                return $attempt;
            }
        }

        return null;
    }

    /**
     * Classify a refund webhook into one of: succeeded, pending, failed, or null (unknown/no-op).
     */
    private function classifyRefundOutcome(string $gateway, string $eventType, ?string $providerStatus): ?string
    {
        if ($gateway === 'stripe' || $gateway === 'fake') {
            return match ($providerStatus ?? '') {
                'succeeded' => 'succeeded',
                'pending', 'requires_action' => 'pending',
                'failed', 'canceled' => 'failed',
                default => null,
            };
        }

        if ($gateway === 'paypal') {
            return match ($eventType) {
                'PAYMENT.CAPTURE.REFUNDED' => 'succeeded',
                'PAYMENT.REFUND.PENDING' => 'pending',
                'PAYMENT.REFUND.FAILED' => 'failed',
                default => null,
            };
        }

        if ($gateway === 'fake' && $eventType === 'payment.refunded') {
            return 'succeeded';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function attemptMetadata(PaymentAttempt $attempt): array
    {
        return $attempt->request_metadata instanceof \ArrayObject
            ? $attempt->request_metadata->getArrayCopy()
            : (array) ($attempt->request_metadata ?? []);
    }
}
