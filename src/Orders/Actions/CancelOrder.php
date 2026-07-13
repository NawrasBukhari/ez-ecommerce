<?php

namespace EzEcommerce\Orders\Actions;

use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\ReservationStatus;
use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Inventory\Actions\ReleaseInventoryReservation;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Actions\VoidPaymentAuthorization;
use EzEcommerce\Payments\Contracts\PaymentOperationPolicy;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\PaymentGatewayRegistry;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CancelOrder
{
    public function __construct(
        private readonly RecordOrderTransition $recordOrderTransition,
        private readonly ReleaseInventoryReservation $releaseInventoryReservation,
        private readonly VoidPaymentAuthorization $voidPaymentAuthorization,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly PaymentOperationPolicy $paymentOperationPolicy,
    ) {
    }

    public function execute(Order $order, ?string $reason = null): Order
    {
        if ($order->status === OrderStatus::Cancelled) {
            return $order;
        }

        $this->assertCancellable($order);

        $order->load('payments');

        foreach ($order->payments as $payment) {
            if (! $payment instanceof Payment) {
                continue;
            }

            if (! $this->paymentOperationPolicy->canVoid($payment)) {
                continue;
            }

            $gateway = $this->gateways->for($payment->gateway);
            if (! $gateway->capabilities()->void) {
                // No provider session to cancel (manual/null gateways); skip voiding.
                continue;
            }

            $this->voidPaymentAuthorization->execute($payment, "void:{$payment->public_id}");
        }

        return DB::transaction(function () use ($order, $reason): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            $this->assertCancellable($locked);

            $from = $locked->status->value;

            $locked->reservations()
                ->where('status', ReservationStatus::Active)
                ->get()
                ->each(fn ($reservation) => $this->releaseInventoryReservation->execute($reservation));

            $locked->update([
                'status' => OrderStatus::Cancelled,
                'fulfillment_status' => FulfillmentStatus::Cancelled,
            ]);

            $this->recordOrderTransition->execute(
                $locked,
                TransitionDimension::Commercial,
                $from,
                OrderStatus::Cancelled->value,
                $reason ?? 'Order cancelled',
            );

            return $locked->fresh();
        });
    }

    private function assertCancellable(Order $order): void
    {
        if ($order->status === OrderStatus::Cancelled) {
            return;
        }

        if (in_array($order->status, [OrderStatus::Completed], true)) {
            throw new RuntimeException('Completed orders cannot be cancelled.');
        }

        if ($order->fulfillment_status === FulfillmentStatus::Fulfilled) {
            throw new RuntimeException('Fulfilled orders cannot be cancelled.');
        }

        if ($order->fulfillment_status === FulfillmentStatus::PartiallyFulfilled) {
            throw new RuntimeException('Partially fulfilled orders cannot be cancelled.');
        }

        if (in_array($order->payment_status, [OrderPaymentStatus::Paid, OrderPaymentStatus::PartiallyPaid], true)) {
            throw new RuntimeException('Paid orders must be refunded before cancellation.');
        }
    }
}
