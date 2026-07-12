<?php

namespace EzEcommerce\Payments\Actions;

use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Inventory\Actions\CommitReservation;
use EzEcommerce\Orders\Actions\ConfirmOrderOnPaymentAccepted;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Payments\Data\CapturePaymentData;
use EzEcommerce\Payments\Data\PaymentResult;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\Event;

final class CapturePayment
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gateways,
        private readonly ApplyPaymentCapture $applyPaymentCapture,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
        private readonly ConfirmOrderOnPaymentAccepted $confirmOrderOnPaymentAccepted,
        private readonly CommitReservation $commitReservation,
    ) {}

    public function execute(Payment $payment, PaymentAttempt $attempt, ?Money $amount = null): PaymentResult
    {
        $amount ??= Money::fromMinor(
            $payment->amount_minor - $payment->captured_minor,
            $payment->currency,
        );

        $gateway = $this->gateways->for($payment->gateway);
        $result = $gateway->capture(new CapturePaymentData($payment, $attempt, $amount));

        if ($result->success) {
            $payment = $this->applyPaymentCapture->execute(
                $payment,
                $attempt,
                $result->amount->minorAmount,
                $result->amount->currency,
                $result->externalId,
                $result->metadata,
            );

            $order = $payment->order;
            $this->recalculateOrderPaymentStatus->execute($order);
            $this->confirmOrderOnPaymentAccepted->execute($order);

            if ($payment->status === PaymentStatus::Captured) {
                $this->commitReservation->executeForOrder($order);
                Event::dispatch(new OrderPaid($order->id, $order->public_id, $payment->id));
            }
        } else {
            $attempt->update([
                'status' => 'failed',
                'error_code' => $result->failure?->code,
                'error_message' => $result->failure?->message,
            ]);
            $payment->update(['status' => PaymentStatus::Failed]);
            $this->recalculateOrderPaymentStatus->execute($payment->order);
        }

        return $result;
    }
}
