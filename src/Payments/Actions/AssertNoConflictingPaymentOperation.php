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
 * exclusive while any of them is in flight. Same-operation in-flight checks
 * remain the responsibility of each action (they reuse idempotency keys
 * and replay semantics specific to that operation).
 *
 * "In flight" is operation-specific:
 *  - unknown: always in flight (provider call threw, outcome uncertain)
 *  - pending create_session with external_id: settled (requires_action follows)
 *  - pending capture with external_id: still settling (e.g. PayPal PENDING) → in flight
 *  - pending void/refund with external_id: in flight (provider call still running)
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

        $inFlight = PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->whereIn('operation', $conflicting)
            ->where(function ($query): void {
                // unknown is always in flight
                $query->where('status', 'unknown')
                    ->orWhere(function ($query): void {
                        // pending is in flight unless it's a settled create_session
                        // (which has an external_id and will move to requires_action)
                        $query->where('status', 'pending')
                            ->where(function ($query): void {
                                $query->where('operation', '!=', 'create_session')
                                    ->orWhereNull('external_id');
                            });
                    });
            })
            ->value('operation');

        if ($inFlight !== null) {
            throw ConflictingPaymentOperationException::for($operation, $inFlight);
        }
    }
}
