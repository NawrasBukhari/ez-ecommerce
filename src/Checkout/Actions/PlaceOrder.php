<?php

namespace EzEcommerce\Checkout\Actions;

use EzEcommerce\Cart\Actions\CalculateCartTotals;
use EzEcommerce\Cart\Exceptions\CartTotalsChangedException;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Checkout\CheckoutResult;
use EzEcommerce\Core\Enums\CartStatus;
use EzEcommerce\Core\Enums\CheckoutStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Events\OrderPaid;
use EzEcommerce\Core\Events\OrderPlaced;
use EzEcommerce\Core\Idempotency\IdempotencyStore;
use EzEcommerce\Customers\Contracts\CustomerResolver;
use EzEcommerce\Customers\Data\CustomerIdentity;
use EzEcommerce\Customers\Data\CustomerResolutionContext;
use EzEcommerce\Customers\Models\Address;
use EzEcommerce\Inventory\Actions\CommitReservation;
use EzEcommerce\Inventory\Actions\ReserveInventory;
use EzEcommerce\Inventory\Contracts\ReservationPolicy;
use EzEcommerce\Orders\Actions\CreateOrderFromCart;
use EzEcommerce\Orders\Actions\RecalculateOrderPaymentStatus;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Payments\Actions\CreatePaymentSession;
use EzEcommerce\Payments\Data\PaymentFailure;
use EzEcommerce\Payments\Models\Payment;
use EzEcommerce\Payments\Models\PaymentAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;

final class PlaceOrder
{
    public function __construct(
        private readonly IdempotencyStore $idempotencyStore,
        private readonly CalculateCartTotals $calculateCartTotals,
        private readonly CustomerResolver $customerResolver,
        private readonly CreateOrderFromCart $createOrderFromCart,
        private readonly ReserveInventory $reserveInventory,
        private readonly ReservationPolicy $reservationPolicy,
        private readonly CreatePaymentSession $createPaymentSession,
        private readonly CommitReservation $commitReservation,
        private readonly RecalculateOrderPaymentStatus $recalculateOrderPaymentStatus,
    ) {}

    public function execute(
        Cart $cart,
        ?Address $shippingAddress = null,
        ?Address $billingAddress = null,
        ?string $shippingMethod = null,
        string $paymentMethod = 'manual',
        string $idempotencyKey = '',
        ?string $expectedTotalsHash = null,
        ?CustomerIdentity $customerIdentity = null,
    ): CheckoutResult {
        $requestHash = hash('sha256', json_encode([
            'cart_id' => $cart->id,
            'shipping_method' => $shippingMethod,
            'payment_method' => $paymentMethod,
            'expected_totals_hash' => $expectedTotalsHash,
        ], JSON_THROW_ON_ERROR));

        ['result' => $cached] = $this->idempotencyStore->execute(
            'checkout',
            $idempotencyKey,
            $requestHash,
            fn () => $this->placeWithinTransaction(
                $cart,
                $shippingAddress,
                $billingAddress,
                $shippingMethod,
                $paymentMethod,
                $expectedTotalsHash,
                $customerIdentity,
            ),
        );

        if ($cached !== null) {
            return $this->hydrateResult(is_array($cached) ? $cached : $cached->getArrayCopy());
        }

        throw new RuntimeException('Checkout idempotency completed without payload.');
    }

    /** @return array{resource_type: string, resource_id: int, payload: array<string, mixed>} */
    private function placeWithinTransaction(
        Cart $cart,
        ?Address $shippingAddress,
        ?Address $billingAddress,
        ?string $shippingMethod,
        string $paymentMethod,
        ?string $expectedTotalsHash,
        ?CustomerIdentity $customerIdentity,
    ): array {
        $commercial = DB::transaction(function () use (
            $cart,
            $shippingAddress,
            $billingAddress,
            $shippingMethod,
            $paymentMethod,
            $expectedTotalsHash,
            $customerIdentity,
        ) {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);

            if ($cart->status !== CartStatus::Active) {
                throw new RuntimeException('Cart is not active.');
            }

            $versionBefore = $cart->version;
            $cart = $this->calculateCartTotals->execute($cart, $shippingMethod, $shippingAddress, $versionBefore);

            if ($expectedTotalsHash !== null) {
                $actualHash = $this->calculateCartTotals->totalsHash($cart, $shippingMethod);
                if ($actualHash !== $expectedTotalsHash) {
                    throw CartTotalsChangedException::for($cart);
                }
            }

            $identity = $customerIdentity ?? new CustomerIdentity;
            $customer = $this->customerResolver->resolve($identity, new CustomerResolutionContext(cart: $cart));
            if ($customer === null) {
                throw new RuntimeException('Customer could not be resolved.');
            }

            $order = $this->createOrderFromCart->execute(
                $cart,
                $customer,
                $shippingMethod,
                $paymentMethod,
                $shippingAddress,
                $billingAddress,
            );

            $this->reserveInventory->executeForCart($cart, $order, $paymentMethod);

            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'gateway' => $paymentMethod,
                'amount_minor' => $order->grand_total_minor,
                'currency' => $order->currency,
                'status' => PaymentStatus::Created,
            ]);

            $attempt = PaymentAttempt::query()->create([
                'payment_id' => $payment->id,
                'operation' => 'create_session',
                'idempotency_key' => $payment->public_id,
                'status' => 'pending',
            ]);

            $cart->update(['status' => CartStatus::Converted]);

            Event::dispatch(new OrderPlaced($order->id, $order->public_id));

            return compact('order', 'payment', 'attempt', 'cart', 'paymentMethod');
        });

        $sessionResult = null;
        $paymentFailure = null;
        $status = CheckoutStatus::PendingPayment;

        try {
            $sessionResult = $this->createPaymentSession->execute(
                $commercial['payment'],
                $commercial['attempt'],
                $commercial['order'],
            );

            if (! $sessionResult->succeeded()) {
                $paymentFailure = $sessionResult->failure;
                $status = CheckoutStatus::PaymentSessionFailed;
            } elseif ($sessionResult->status === PaymentStatus::RequiresAction) {
                $status = CheckoutStatus::RequiresAction;
            } elseif ($sessionResult->status === PaymentStatus::Captured) {
                $this->commitReservation->executeForOrder($commercial['order']);
                $commercial['order']->update(['status' => OrderStatus::Confirmed]);
                Event::dispatch(new OrderPaid($commercial['order']->id, $commercial['order']->public_id, $commercial['payment']->id));
                $status = CheckoutStatus::Completed;
            } elseif ($this->reservationPolicy->shouldCommitImmediately($commercial['order'], $paymentMethod)) {
                $this->commitReservation->executeForOrder($commercial['order']);
            }
        } catch (\Throwable $e) {
            $commercial['attempt']->update([
                'status' => 'failed_retryable',
                'error_code' => 'session_exception',
                'error_message' => $e->getMessage(),
            ]);
            $paymentFailure = new PaymentFailure('session_exception', $e->getMessage(), true);
            $status = CheckoutStatus::PaymentSessionFailed;
        }

        $this->recalculateOrderPaymentStatus->execute($commercial['order']);

        $result = new CheckoutResult(
            order: $commercial['order']->fresh(),
            payment: $commercial['payment']->fresh(),
            paymentSession: $sessionResult,
            status: $status,
            paymentFailure: $paymentFailure,
        );

        return [
            'resource_type' => 'commerce_order',
            'resource_id' => $commercial['order']->id,
            'payload' => [
                'order_id' => $commercial['order']->id,
                'payment_id' => $commercial['payment']->id,
                'status' => $status->value,
                'payment_failure' => $paymentFailure ? [
                    'code' => $paymentFailure->code,
                    'message' => $paymentFailure->message,
                    'retryable' => $paymentFailure->retryable,
                ] : null,
            ],
        ];
    }

    /** @param  array<string, mixed>  $cached */
    private function hydrateResult(array $cached): CheckoutResult
    {
        $order = Order::query()->findOrFail($cached['order_id']);
        $payment = Payment::query()->findOrFail($cached['payment_id']);

        return new CheckoutResult(
            order: $order,
            payment: $payment,
            paymentSession: null,
            status: CheckoutStatus::from($cached['status']),
            paymentFailure: isset($cached['payment_failure'])
                ? new PaymentFailure(
                    $cached['payment_failure']['code'],
                    $cached['payment_failure']['message'],
                    $cached['payment_failure']['retryable'],
                )
                : null,
        );
    }
}
