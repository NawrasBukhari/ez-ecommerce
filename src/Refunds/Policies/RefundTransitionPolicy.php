<?php

namespace EzEcommerce\Refunds\Policies;

use EzEcommerce\Core\Enums\RefundStatus;

/**
 * Enforces monotonic refund status transitions.
 *
 * Succeeded is terminal: a late pending or failed webhook must never regress a
 * refund that already succeeded. Failed is terminal except via explicit
 * reconciliation (Failed → Succeeded when the provider proves success). A
 * delayed pending webhook must not regress a Failed refund back to Pending.
 */
final class RefundTransitionPolicy
{
    public function canTransition(RefundStatus $from, RefundStatus $to): bool
    {
        if ($to === RefundStatus::Succeeded) {
            // Reconciliation from Failed and normal completion from Pending are
            // both allowed; Succeeded → Succeeded is an idempotent no-op.
            return true;
        }

        // Succeeded is terminal — no regression to Pending or Failed.
        if ($from === RefundStatus::Succeeded) {
            return false;
        }

        // Failed is terminal except via explicit reconciliation to Succeeded
        // (handled above). A delayed pending webhook must not regress Failed.
        if ($from === RefundStatus::Failed && $to === RefundStatus::Pending) {
            return false;
        }

        // Pending → Failed is a normal decline; Pending → Pending is a no-op.
        return true;
    }
}
