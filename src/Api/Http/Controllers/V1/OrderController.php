<?php

namespace EzEcommerce\Api\Http\Controllers\V1;

use EzEcommerce\Api\Http\Resources\FulfillmentResource;
use EzEcommerce\Api\Http\Resources\OrderResource;
use EzEcommerce\Api\Http\Resources\OrderTransitionResource;
use EzEcommerce\Api\Http\Resources\PaymentResource;
use EzEcommerce\Api\Http\Resources\PaymentTransactionResource;
use EzEcommerce\Api\Http\Resources\RefundResource;
use EzEcommerce\Core\Idempotency\IdempotencyStore;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Core\Support\CanonicalJson;
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
use RuntimeException;

final class OrderController extends Controller
{
    public function __construct(
        private readonly OrderManager $orderManager,
        private readonly CapturePayment $capturePayment,
        private readonly RefundPayment $refundPayment,
        private readonly RetryPaymentSession $retryPaymentSession,
        private readonly CancelOrder $cancelOrder,
        private readonly CompleteOrder $completeOrder,
        private readonly IdempotencyStore $idempotencyStore,
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
        $idempotencyKey = $this->requireIdempotencyKey($request);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string'],
        ]);

        $reason = $validated['reason'] ?? null;
        $requestHash = hash('sha256', CanonicalJson::encode([
            'order_id' => $order->id,
            'reason' => $reason,
        ]));

        $orderId = $this->runIdempotentOrderMutation(
            scope: 'order_cancel',
            idempotencyKey: $idempotencyKey,
            requestHash: $requestHash,
            operation: fn () => $this->cancelOrder->execute($order, $reason)->id,
        );

        return new OrderResource(Order::query()->with(['items', 'payments'])->findOrFail($orderId));
    }

    public function complete(Request $request, Order $order): OrderResource
    {
        $idempotencyKey = $this->requireIdempotencyKey($request);

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string'],
        ]);

        $reason = $validated['reason'] ?? null;
        $requestHash = hash('sha256', CanonicalJson::encode([
            'order_id' => $order->id,
            'reason' => $reason,
        ]));

        $orderId = $this->runIdempotentOrderMutation(
            scope: 'order_complete',
            idempotencyKey: $idempotencyKey,
            requestHash: $requestHash,
            operation: fn () => $this->completeOrder->execute($order, $reason)->id,
        );

        return new OrderResource(Order::query()->with(['items', 'payments'])->findOrFail($orderId));
    }

    public function capture(Request $request, Order $order): OrderResource
    {
        $idempotencyKey = $this->requireIdempotencyKey($request);

        $payment = $order->payments()->latest()->firstOrFail();

        $this->capturePayment->executeForPayment($payment, null, $idempotencyKey);

        $order->refresh()->load('items', 'payments');

        return new OrderResource($order);
    }

    public function fulfill(Request $request, Order $order): JsonResponse
    {
        $idempotencyKey = $this->requireIdempotencyKey($request);

        $validated = $request->validate([
            'order_item_id' => ['required', 'integer'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $requestHash = hash('sha256', CanonicalJson::encode([
            'order_id' => $order->id,
            'order_item_id' => $validated['order_item_id'],
            'quantity' => $validated['quantity'],
        ]));

        ['result' => $cached] = $this->idempotencyStore->execute(
            'order_fulfill',
            $idempotencyKey,
            $requestHash,
            function () use ($order, $validated, $idempotencyKey): array {
                $item = OrderItem::query()
                    ->where('order_id', $order->id)
                    ->where('id', $validated['order_item_id'])
                    ->firstOrFail();

                $fulfillment = $this->orderManager->fulfill(
                    $order,
                    $item,
                    $validated['quantity'],
                    "order_fulfill:{$idempotencyKey}",
                );

                return [
                    'resource_type' => 'fulfillment',
                    'resource_id' => $fulfillment->id,
                    'payload' => [
                        'fulfillment_id' => $fulfillment->public_id,
                        'order_item_id' => $item->id,
                        'quantity' => $validated['quantity'],
                    ],
                ];
            },
        );

        $payload = is_array($cached) ? $cached : [];

        return response()->json(['data' => $payload], 201);
    }

    public function refund(Request $request, Order $order): RefundResource
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['sometimes', 'nullable', 'string'],
            'idempotency_key' => ['sometimes', 'nullable', 'string'],
        ]);

        $idempotencyKey = $validated['idempotency_key']
            ?? $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            abort(422, 'Idempotency-Key header or idempotency_key body field is required.');
        }

        /** @var Payment $payment */
        $payment = $order->payments()->latest()->firstOrFail();

        $refund = $this->refundPayment->execute(
            $payment,
            Money::fromMinor($validated['amount_minor'], $payment->currency),
            $validated['reason'] ?? null,
            $idempotencyKey,
        );

        return new RefundResource($refund);
    }

    public function retryPayment(Request $request, Order $order): JsonResponse
    {
        $idempotencyKey = $this->requireIdempotencyKey($request);

        /** @var Payment $payment */
        $payment = $order->payments()->latest()->firstOrFail();

        $result = $this->retryPaymentSession->execute($payment, $order, $idempotencyKey);

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

    private function requireIdempotencyKey(Request $request): string
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            abort(422, 'Idempotency-Key header is required.');
        }

        return $idempotencyKey;
    }

    /** @param  callable(): int  $operation */
    private function runIdempotentOrderMutation(
        string $scope,
        string $idempotencyKey,
        string $requestHash,
        callable $operation,
    ): int {
        ['result' => $cached] = $this->idempotencyStore->execute(
            $scope,
            $idempotencyKey,
            $requestHash,
            function () use ($operation): array {
                $orderId = $operation();

                return [
                    'resource_type' => 'order',
                    'resource_id' => $orderId,
                    'payload' => ['order_id' => $orderId],
                ];
            },
        );

        if ($cached === null) {
            throw new RuntimeException("{$scope} idempotency completed without payload.");
        }

        return (int) ($cached['order_id'] ?? 0);
    }
}
