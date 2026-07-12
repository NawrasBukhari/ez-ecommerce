<?php

namespace EzEcommerce\Checkout\Actions;

use EzEcommerce\Cart\Actions\CalculateCartTotals;
use EzEcommerce\Cart\Exceptions\CartTotalsChangedException;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Checkout\CheckoutResult;
use EzEcommerce\Core\Enums\CartStatus;
use EzEcommerce\Core\Enums\CheckoutStatus;
use EzEcommerce\Core\Enums\PaymentStatus;
use EzEcommerce\Core\Events\OrderPlaced;
use EzEcommerce\Core\Idempotency\IdempotencyStore;
use EzEcommerce\Core\Support\CanonicalJson;
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
        if ($idempotencyKey === '') {
            throw new RuntimeException('Checkout idempotency key is required.');
        }

        if ($expectedTotalsHash === null || $expectedTotalsHash === '') {
            throw new RuntimeException('Expected totals hash is required.');
        }

        $cart = Cart::query()->findOrFail($cart->id);
        $identity = $customerIdentity ?? new CustomerIdentity;

        $metadata = $cart->metadata instanceof \ArrayObject
            ? $cart->metadata->getArrayCopy()
            : (array) ($cart->metadata ?? []);

        $requestHash = hash('sha256', CanonicalJson::encode([
            'cart_id' => $cart->id,
            'customer_identity' => $identity->idempotencyFingerprint(),
            'shipping_address_id' => $shippingAddress?->id,
            'billing_address_id' => $billingAddress?->id,
            'price_list_id' => $metadata['price_list_id'] ?? null,
            'shipping_method' => $shippingMethod,
            'payment_method' => $paymentMethod,
            'expected_totals_hash' => $expectedTotalsHash,
        ]));

        ['result' => $cached] = $this->idempotencyStore->execute(
            'checkout',
            $idempotencyKey,
            $requestHash,
            fn () => $this->placeWithinTransaction(
                $cart,
                $identity,
                $shippingAddress,
                $billingAddress,
                $shippingMethod,
                $paymentMethod,
                $expectedTotalsHash,
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
        CustomerIdentity $customerIdentity,
        ?Address $shippingAddress,
        ?Address $billingAddress,
        ?string $shippingMethod,
        string $paymentMethod,
        string $expectedTotalsHash,
    ): array {
        $commercial = DB::transaction(function () use (
            $cart,
            $customerIdentity,
            $shippingAddress,
            $billingAddress,
            $shippingMethod,
            $paymentMethod,
            $expectedTotalsHash,
        ) {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);

            if ($cart->status !== CartStatus::Active) {
                throw new RuntimeException('Cart is not active.');
            }

            $customer = $this->customerResolver->resolve(
                $customerIdentity,
                new CustomerResolutionContext(cart: $cart),
            );
            if ($customer === null) {
                throw new RuntimeException('Customer could not be resolved.');
            }

            if ($cart->customer_id !== $customer->id) {
                $cart->update(['customer_id' => $customer->id]);
            }
            $cart->setRelation('customer', $customer->loadMissing('customerGroup'));

            $versionBefore = $cart->version;
            $cart = $this->calculateCartTotals->execute($cart, $shippingMethod, $shippingAddress, $versionBefore);

            $actualHash = $this->calculateCartTotals->totalsHash($cart, $shippingMethod);
            if ($actualHash !== $expectedTotalsHash) {
                throw CartTotalsChangedException::for($cart);
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
