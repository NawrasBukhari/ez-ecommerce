<?php

namespace EzEcommerce\Refunds\Policies;

use EzEcommerce\Core\Enums\RefundStatus;

/**
 * Enforces monotonic refund status transitions.
 *
 * Succeeded is terminal: a late pending or failed webhook must never regress a
 * refund that already succeeded. Failed → Succeeded is permitted only as an
 * explicit reconciliation (operator/provider confirmation), never an automatic
 * regression the other way.
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

        return true;
    }
}
