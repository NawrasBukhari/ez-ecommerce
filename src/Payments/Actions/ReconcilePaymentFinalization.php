<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use Illuminate\Support\Collection;
use RuntimeException;

final class ReconcilePaymentFinalization
{
    public function __construct(
        private readonly FinalizeAcceptedPayment $finalizeAcceptedPayment,
    ) {}

    /** @return Collection<int, Payment> */
    public function pendingPayments(): Collection
    {
        $paymentIds = PaymentAttempt::query()
            ->where('error_code', 'finalization_exception')
            ->where('status', 'failed')
            ->pluck('payment_id');

        return Payment::query()
            ->whereIn('id', $paymentIds)
            ->orWhereHas('order', function ($query) {
                $query->where('metadata->manual_review_required', 'inventory_exception');
            })
            ->whereIn('status', [PaymentStatus::Captured, PaymentStatus::PartiallyCaptured])
            ->with('order')
            ->orderBy('id')
            ->get();
    }

    public function complete(Payment $payment): void
    {
        $payment->refresh();

        if (! in_array($payment->status, [PaymentStatus::Captured, PaymentStatus::PartiallyCaptured], true)) {
            throw new RuntimeException("Payment [{$payment->id}] is not captured.");
        }

        $this->finalizeAcceptedPayment->completeOrderAfterCapture($payment);

        /** @var Order $order */
        $order = $payment->order;
        $metadata = $order->metadata instanceof \ArrayObject
            ? $order->metadata->getArrayCopy()
            : (array) ($order->metadata ?? []);
        unset($metadata['manual_review_required']);
        $order->update(['metadata' => $metadata]);
    }
}
