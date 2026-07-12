<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;

final class RecordInventoryFinalizationFailure
{
    public function execute(Payment $payment, ?PaymentAttempt $attempt = null, ?string $message = null): void
    {
        $order = $payment->order;
        if ($order === null) {
            return;
        }

        $metadata = $order->metadata instanceof \ArrayObject
            ? $order->metadata->getArrayCopy()
            : (array) ($order->metadata ?? []);
        $metadata['manual_review_required'] = 'inventory_exception';
        if ($message !== null && $message !== '') {
            $metadata['manual_review_error'] = $message;
        }
        $order->update(['metadata' => $metadata]);

        if ($attempt !== null) {
            $attempt->update([
                'error_code' => 'finalization_exception',
                'error_message' => $message,
            ]);
        }
    }
}
