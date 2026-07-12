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
use EzEcommerce\Orders\Models\Order;
use EzEcommerce\Orders\Models\OrderAdjustment;
use EzEcommerce\Orders\Models\OrderItem;
use EzEcommerce\Pricing\Contracts\PriceResolver;
use EzEcommerce\Pricing\Data\PricingContext;

final class CreateOrderFromCart
{
    public function __construct(
        private readonly PriceResolver $priceResolver,
        private readonly RecordOrderTransition $recordOrderTransition,
    ) {}

    public function execute(
        Cart $cart,
        Customer $customer,
        ?string $shippingMethod = null,
        ?string $paymentMethod = null,
        ?Address $shippingAddress = null,
        ?Address $billingAddress = null,
    ): Order {
        $cart->load('items.purchasable', 'adjustments');

        $order = Order::query()->create([
            'customer_id' => $customer->id,
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
            'metadata' => [
                'shipping_address_id' => $shippingAddress?->id,
                'billing_address_id' => $billingAddress?->id,
            ],
        ]);

        foreach ($cart->items as $cartItem) {
            $purchasable = $cartItem->purchasable;
            $quote = $this->priceResolver->resolve($purchasable, new PricingContext(
                currency: $cart->currency,
                quantity: $cartItem->quantity,
                customer: $customer,
            ));

            $lineSubtotal = $quote->unitPrice->multiply($cartItem->quantity);

            OrderItem::query()->create([
                'order_id' => $order->id,
                'name' => $purchasable->purchasableName(),
                'sku' => $purchasable instanceof ProductVariant
                    ? $purchasable->sku
                    : ($purchasable->purchasableMetadata()['sku'] ?? null),
                'quantity' => $cartItem->quantity,
                'unit_price_minor' => $quote->unitPrice->minorAmount,
                'subtotal_minor' => $lineSubtotal->minorAmount,
                'total_minor' => $lineSubtotal->minorAmount,
                'price_source' => $quote->source,
                'price_record_id' => $quote->priceId,
                'price_quote_hash' => $quote->fingerprint(),
                'price_metadata' => $quote->metadata,
                'priced_at' => new DateTimeImmutable,
                'product_snapshot' => array_merge($purchasable->purchasableMetadata(), [
                    'purchasable_type' => $cartItem->purchasable_type,
                    'purchasable_id' => $cartItem->purchasable_id,
                    'purchasable_public_id' => $purchasable->purchasableId(),
                    'name' => $purchasable->purchasableName(),
                ]),
            ]);
        }

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

        return $order->fresh(['items', 'adjustments']);
    }
}
