<?php

namespace EzEcommerce\Orders\Actions;

use DateTimeImmutable;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Catalog\Models\ProductVariant;
use EzEcommerce\Core\Enums\FulfillmentStatus;
use EzEcommerce\Core\Enums\OrderPaymentStatus;
use EzEcommerce\Core\Enums\OrderStatus;
use EzEcommerce\Core\Enums\TransitionDimension;
use EzEcommerce\Customers\Models\Address;
use EzEcommerce\Customers\Models\Customer;
use EzEcommerce\Marketplace\Actions\RecordVendorCommissions;
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderAddress;
use EzEcommerce\Orders\Models\OrderAdjustment;
use EzEcommerce\Orders\Models\OrderItem;
use EzEcommerce\Stores\Contracts\StoreContext;

final class CreateOrderFromCart
{
    public function __construct(
        private readonly RecordOrderTransition $recordOrderTransition,
        private readonly AllocateLineDiscounts $allocateLineDiscounts,
        private readonly RecordVendorCommissions $recordVendorCommissions,
        private readonly StoreContext $storeContext,
    ) {
    }

    public function execute(
        Cart $cart,
        Customer $customer,
        ?string $shippingMethod = null,
        ?string $paymentMethod = null,
        ?Address $shippingAddress = null,
        ?Address $billingAddress = null,
    ): Order {
        $cart->load('items.purchasable', 'adjustments');

        $customerName = trim(trim((string) $customer->first_name).' '.trim((string) $customer->last_name));
        if ($customerName === '') {
            $customerName = (string) $customer->email;
        }

        $metadata = [
            'shipping_address_id' => $shippingAddress?->id,
            'billing_address_id' => $billingAddress?->id,
        ];

        if ($paymentMethod === 'net_terms' && config('ez-ecommerce.features.b2b', false)) {
            $customer->loadMissing('company');
            $termsDays = $customer->company?->payment_terms_days;
            if ($termsDays !== null && $termsDays > 0) {
                $metadata['payment_terms_days'] = $termsDays;
                $metadata['payment_terms_due_at'] = (new DateTimeImmutable)
                    ->modify("+{$termsDays} days")
                    ->format(DateTimeImmutable::ATOM);
            }
        }

        $order = Order::query()->create([
            'store_id' => $cart->store_id ?? $this->storeContext->id(),
            'customer_id' => $customer->id,
            'customer_email' => $customer->email,
            'customer_name' => $customerName,
            'customer_phone' => $customer->phone,
            'cart_id' => $cart->id,
            'status' => OrderStatus::PendingPayment,
            'payment_status' => OrderPaymentStatus::Unpaid,
            'fulfillment_status' => FulfillmentStatus::Unfulfilled,
            'currency' => $cart->currency,
            'subtotal_minor' => $cart->subtotal_minor,
            'discount_total_minor' => $cart->discount_total_minor,
            'tax_total_minor' => $cart->tax_total_minor,
            'shipping_total_minor' => $cart->shipping_total_minor,
            'fee_total_minor' => $cart->fee_total_minor,
            'grand_total_minor' => $cart->grand_total_minor,
            'shipping_method' => $shippingMethod,
            'payment_method' => $paymentMethod,
            'metadata' => $metadata,
        ]);

        foreach ($cart->items as $cartItem) {
            $purchasable = $cartItem->purchasable;
            $lineSubtotal = $cartItem->unit_price_minor * $cartItem->quantity;
            $itemMeta = $cartItem->metadata instanceof \ArrayObject
                ? $cartItem->metadata->getArrayCopy()
                : (array) ($cartItem->metadata ?? []);

            OrderItem::query()->create([
                'order_id' => $order->id,
                'name' => $purchasable->purchasableName(),
                'sku' => $purchasable instanceof ProductVariant
                    ? $purchasable->sku
                    : ($purchasable->purchasableMetadata()['sku'] ?? null),
                'quantity' => $cartItem->quantity,
                'unit_price_minor' => $cartItem->unit_price_minor,
                'subtotal_minor' => $lineSubtotal,
                'total_minor' => $lineSubtotal,
                'price_source' => $itemMeta['price_source'] ?? 'cart',
                'price_record_id' => $itemMeta['price_record_id'] ?? null,
                'price_quote_hash' => $itemMeta['price_quote_hash'] ?? hash('sha256', $cartItem->unit_price_minor.':'.$cartItem->quantity),
                'price_metadata' => $itemMeta['price_metadata'] ?? [],
                'priced_at' => new DateTimeImmutable,
                'product_snapshot' => array_merge($purchasable->purchasableMetadata(), [
                    'purchasable_type' => $cartItem->purchasable_type,
                    'purchasable_id' => $cartItem->purchasable_id,
                    'purchasable_public_id' => $purchasable->purchasableId(),
                    'name' => $purchasable->purchasableName(),
                ]),
            ]);
        }

        $this->snapshotAddress($order, 'shipping', $shippingAddress);
        $this->snapshotAddress($order, 'billing', $billingAddress);

        foreach ($cart->adjustments as $adjustment) {
            OrderAdjustment::query()->create([
                'order_id' => $order->id,
                'type' => $adjustment->type,
                'source_type' => $adjustment->source_type,
                'source_id' => $adjustment->source_id,
                'code' => $adjustment->code,
                'label' => $adjustment->label,
                'amount_minor' => $adjustment->amount_minor,
                'currency' => $adjustment->currency,
                'origin' => $adjustment->origin,
                'included_in_unit_price' => $adjustment->included_in_unit_price,
                'affects_total' => $adjustment->affects_total,
                'metadata' => $adjustment->metadata?->toArray(),
            ]);
        }

        $this->recordOrderTransition->execute(
            $order,
            TransitionDimension::Commercial,
            '',
            OrderStatus::PendingPayment->value,
            'Order created from cart',
        );

        $order = $this->allocateLineDiscounts->execute($order);
        $order = $this->recordVendorCommissions->execute($order);

        return $order->fresh(['items', 'adjustments', 'addresses']);
    }

    private function snapshotAddress(Order $order, string $type, ?Address $address): void
    {
        if ($address === null) {
            return;
        }

        OrderAddress::query()->create([
            'order_id' => $order->id,
            'type' => $type,
            'line1' => $address->line1,
            'line2' => $address->line2,
            'city' => $address->city,
            'state' => $address->state,
            'postal_code' => $address->postal_code,
            'country_code' => $address->country_code,
            'metadata' => $address->metadata?->toArray(),
        ]);
    }
}
