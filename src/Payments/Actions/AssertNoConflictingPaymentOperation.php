<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Payments\Exceptions\ConflictingPaymentOperationException;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;

/**
 * Payment-wide conflict guard. Before starting a provider operation, assert no
 * incompatible operation is in flight on the same payment. Run inside the
 * payment row lock so the check is serialized across workers.
 *
 * Compatibility matrix: capture, void, refund, and create_session are mutually
 * exclusive while any of them is pending or unknown. Same-operation in-flight
 * checks remain the responsibility of each action (they reuse idempotency keys
 * and replay semantics specific to that operation).
 */
final class AssertNoConflictingPaymentOperation
{
    /** @var array<string, list<string>> */
    private const CONFLICTS = [
        'capture' => ['void', 'refund', 'create_session'],
        'void' => ['capture', 'refund', 'create_session'],
        'refund' => ['capture', 'void', 'create_session'],
        'create_session' => ['capture', 'void', 'refund'],
    ];

    public function execute(Payment $payment, string $operation): void
    {
        $conflicting = self::CONFLICTS[$operation] ?? [];

        if ($conflicting === []) {
            return;
        }

        // An attempt is "in flight" when it is unknown (the provider call threw
        // and the outcome is uncertain) or pending without an external id (the
        // provider call is still running). A pending attempt that already has an
        // external id is settled — e.g. a manual payment session left pending, or
        // a PayPal pending capture — and must not block a compatible follow-up.
        $inFlight = PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->whereIn('operation', $conflicting)
            ->where(function ($query): void {
                $query->where('status', 'unknown')
                    ->orWhere(function ($query): void {
                        $query->where('status', 'pending')
                            ->whereNull('external_id');
                    });
            })
            ->value('operation');

        if ($inFlight !== null) {
            throw ConflictingPaymentOperationException::for($operation, $inFlight);
        }
    }
}
