<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\FulfillmentResource;
use EzEcommerce\Api\Http\Resources\OrderResource;
use EzEcommerce\Api\Http\Resources\OrderTransitionResource;
use EzEcommerce\Api\Http\Resources\PaymentResource;
use EzEcommerce\Api\Http\Resources\PaymentTransactionResource;
use EzEcommerce\Api\Http\Resources\RefundResource;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Fulfillment\Models\Fulfillment;
use EzEcommerce\Orders\Actions\CancelOrder;
use EzEcommerce\Orders\Actions\CompleteOrder;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use EzEcommerce\Orders\OrderManager;
use EzEcommerce\Payments\Actions\CapturePayment;
use EzEcommerce\Payments\Actions\RetryPaymentSession;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Refunds\Actions\RefundPayment;
use EzEcommerce\Refunds\Models\Refund;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderManager $orderManager,
        private readonly CapturePayment $capturePayment,
        private readonly RefundPayment $refundPayment,
        private readonly RetryPaymentSession $retryPaymentSession,
        private readonly CancelOrder $cancelOrder,
        private readonly CompleteOrder $completeOrder,
    ) {
    }

    public function show(Order $order): OrderResource
    {
        $order->load('items', 'payments');

        return new OrderResource($order);
    }

    public function transitions(Order $order): AnonymousResourceCollection
    {
        return OrderTransitionResource::collection(
            $order->transitions()->latest()->get(),
        );
    }

    public function fulfillments(Order $order): AnonymousResourceCollection
    {
        return FulfillmentResource::collection(
            $order->fulfillments()->latest()->get(),
        );
    }

    public function refunds(Order $order): AnonymousResourceCollection
    {
        return RefundResource::collection(
            Refund::query()->where('order_id', $order->id)->latest()->get(),
        );
    }

    public function payments(Order $order): AnonymousResourceCollection
    {
        return PaymentResource::collection(
            $order->payments()->latest()->get(),
        );
    }

    public function paymentTransactions(Order $order, Payment $payment): AnonymousResourceCollection
    {
        abort_if($payment->order_id !== $order->id, 404);

        return PaymentTransactionResource::collection(
            $payment->transactions()->latest()->get(),
        );
    }

    public function cancel(Request $request, Order $order): OrderResource
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string'],
        ]);

        $order = $this->cancelOrder->execute($order, $validated['reason'] ?? null);

        return new OrderResource($order->load('items', 'payments'));
    }

    public function complete(Request $request, Order $order): OrderResource
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string'],
        ]);

        $order = $this->completeOrder->execute($order, $validated['reason'] ?? null);

        return new OrderResource($order->load('items', 'payments'));
    }

    public function capture(Order $order): OrderResource
    {
        $payment = $order->payments()->latest()->firstOrFail();

        $this->capturePayment->executeForPayment($payment);

        $order->refresh()->load('items', 'payments');

        return new OrderResource($order);
    }

    public function fulfill(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'order_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $item = OrderItem::query()
            ->where('order_id', $order->id)
            ->where('id', $validated['order_item_id'])
            ->firstOrFail();

        $this->orderManager->fulfill($order, $item, $validated['quantity']);

        $fulfillment = Fulfillment::query()
            ->where('order_id', $order->id)
            ->where('order_item_id', $item->id)
            ->latest()
            ->first();

        return response()->json([
            'data' => [
                'id' => $fulfillment?->public_id,
                'order_item_id' => $item->id,
                'quantity' => $validated['quantity'],
            ],
        ], 201);
    }

    public function refund(Request $request, Order $order): RefundResource
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string'],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
        ]);

        /** @var Payment $payment */
        $payment = $order->payments()->latest()->firstOrFail();

        $refund = $this->refundPayment->execute(
            $payment,
            Money::fromMinor($validated['amount_minor'], $payment->currency),
            $validated['reason'] ?? null,
            $validated['idempotency_key']
                ?? $request->header('Idempotency-Key')
                ?? '',
        );

        return new RefundResource($refund);
    }

    public function retryPayment(Order $order): JsonResponse
    {
        /** @var Payment $payment */
        $payment = $order->payments()->latest()->firstOrFail();

        $result = $this->retryPaymentSession->execute($payment, $order);

        $payment->refresh();

        return response()->json([
            'payment' => new PaymentResource($payment),
            'session' => [
                'status' => $result->status->value,
                'external_id' => $result->externalId,
                'redirect_url' => $result->redirectUrl,
                'client_secret' => $result->clientSecret,
                'retryable' => $result->failure?->retryable,
                'error_code' => $result->failure?->code,
                'error_message' => $result->failure?->message,
            ],
        ]);
    }
}
