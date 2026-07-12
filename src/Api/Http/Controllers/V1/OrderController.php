<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\OrderResource;
use EzEcommerce\Api\Http\Resources\PaymentResource;
use EzEcommerce\Api\Http\Resources\RefundResource;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Fulfillment\Models\Fulfillment;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderItem;
use EzEcommerce\Orders\OrderManager;
use EzEcommerce\Payments\Actions\CapturePayment;
use EzEcommerce\Payments\Actions\RetryPaymentSession;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use EzEcommerce\Refunds\Actions\RefundPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderManager $orderManager,
        private readonly CapturePayment $capturePayment,
        private readonly RefundPayment $refundPayment,
        private readonly RetryPaymentSession $retryPaymentSession,
    ) {}

    public function show(Order $order): OrderResource
    {
        $order->load('items', 'payments');

        return new OrderResource($order);
    }

    public function capture(Order $order): OrderResource
    {
        $payment = $order->payments()->latest()->firstOrFail();
        $attempt = PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->latest()
            ->firstOrFail();

        $this->capturePayment->execute($payment, $attempt);

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

        $attempt = PaymentAttempt::query()
            ->where('payment_id', $payment->id)
            ->latest()
            ->firstOrFail();

        $result = $this->retryPaymentSession->execute($payment, $attempt, $order);

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
