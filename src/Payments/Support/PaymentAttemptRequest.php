<?php

namespace EzEcommerce\Payments\Support;

use EzEcommerce\Core\Money\Money;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;

final class PaymentAttemptRequest
{
    /** @return array<string, mixed> */
    public static function metadata(PaymentAttempt $attempt): array
    {
        return $attempt->request_metadata instanceof \ArrayObject
            ? $attempt->request_metadata->getArrayCopy()
            : (array) ($attempt->request_metadata ?? []);
    }

    /** @param  array<string, mixed>  $snapshot */
    public static function merge(PaymentAttempt $attempt, array $snapshot): void
    {
        $attempt->update([
            'request_metadata' => array_merge(self::metadata($attempt), $snapshot),
        ]);
    }

    public static function captureAmount(PaymentAttempt $attempt, Payment $payment): ?Money
    {
        $metadata = self::metadata($attempt);
        if (! isset($metadata['requested_amount_minor'])) {
            return null;
        }

        return Money::fromMinor(
            (int) $metadata['requested_amount_minor'],
            (string) ($metadata['currency'] ?? $payment->currency),
        );
    }
}
