<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Exceptions\CartVersionConflictException;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartAdjustment;
use EzEcommerce\Catalog\Contracts\Shippable;
use EzEcommerce\Catalog\Contracts\Taxable;
use EzEcommerce\Core\Enums\AdjustmentOrigin;
use EzEcommerce\Core\Enums\AdjustmentType;
use EzEcommerce\Core\Money\Money;
use EzEcommerce\Core\Support\CanonicalJson;
use EzEcommerce\Customers\Models\Address;
use EzEcommerce\Pricing\Contracts\PriceResolver;
use EzEcommerce\Pricing\Data\PricingContext;
use EzEcommerce\Pricing\Models\PriceList;
use EzEcommerce\Shipping\Contracts\ShippingCalculator;
use EzEcommerce\Shipping\Data\ShippingRequest;
use EzEcommerce\Taxes\Contracts\TaxCalculator;
use EzEcommerce\Taxes\Data\TaxRequest;
use Illuminate\Support\Facades\DB;

final class CalculateCartTotals
{
    public function __construct(
        private readonly PriceResolver $priceResolver,
        private readonly TaxCalculator $taxCalculator,
        private readonly ShippingCalculator $shippingCalculator,
    ) {}

    public function execute(
        Cart $cart,
        ?string $shippingMethod = null,
        ?Address $shippingAddress = null,
        ?int $expectedVersion = null,
    ): Cart {
        return DB::transaction(function () use ($cart, $shippingMethod, $shippingAddress, $expectedVersion) {
            $cart = Cart::query()->lockForUpdate()->findOrFail($cart->id);

            if ($expectedVersion !== null && $cart->version !== $expectedVersion) {
                throw CartVersionConflictException::for($cart);
            }

            $cart->load('items.purchasable', 'adjustments', 'customer.customerGroup');

            $priceList = $this->resolvePriceList($cart);
            $customerGroup = $cart->customer?->customerGroup;

            $subtotalMinor = 0;
            $shippingLines = [];
            $taxLines = [];

            foreach ($cart->items as $item) {
                $purchasable = $item->purchasable;
                $quote = $this->priceResolver->resolve($purchasable, new PricingContext(
                    currency: $cart->currency,
                    quantity: $item->quantity,
                    customer: $cart->customer,
                    customerGroup: $customerGroup,
                    priceList: $priceList,
                ));

                $lineSubtotal = $quote->unitPrice->multiply($item->quantity);
                $subtotalMinor += $lineSubtotal->minorAmount;

                $item->update([
                    'unit_price_minor' => $quote->unitPrice->minorAmount,
                    'currency' => $quote->unitPrice->currency,
                ]);

                $shippingLines[] = [
                    'requires_shipping' => $purchasable instanceof Shippable && $purchasable->requiresShipping(),
                    'weight_grams' => $purchasable instanceof Shippable ? $purchasable->weightGrams() : null,
                    'quantity' => $item->quantity,
                ];

                $taxLines[] = [
                    'taxable' => $purchasable instanceof Taxable,
                    'amount' => $lineSubtotal,
                    'tax_category' => $purchasable instanceof Taxable ? $purchasable->taxCategory() : null,
                ];
            }

            CartAdjustment::query()
                ->where('cart_id', $cart->id)
                ->where('origin', AdjustmentOrigin::System)
                ->delete();

            $subtotal = Money::fromMinor($subtotalMinor, $cart->currency);
            $manualDiscountMinor = $cart->adjustments
                ->where('origin', '!=', AdjustmentOrigin::System)
                ->where('type', AdjustmentType::Discount)
                ->sum('amount_minor');
            $discountTotal = Money::fromMinor(abs($manualDiscountMinor), $cart->currency);

            $shippingQuote = $this->shippingCalculator->quote(new ShippingRequest(
                currency: $cart->currency,
                method: $shippingMethod,
                shippingAddress: $shippingAddress,
                lines: $shippingLines,
            ));

            if (! $shippingQuote->amount->isZero()) {
                CartAdjustment::query()->create([
                    'cart_id' => $cart->id,
                    'type' => AdjustmentType::Shipping,
                    'code' => $shippingQuote->method,
                    'label' => $shippingQuote->label,
                    'amount_minor' => $shippingQuote->amount->minorAmount,
                    'currency' => $shippingQuote->amount->currency,
                    'origin' => AdjustmentOrigin::System,
                    'affects_total' => true,
                ]);
            }

            $taxResult = $this->taxCalculator->calculate(new TaxRequest(
                subtotal: $subtotal,
                discountTotal: $discountTotal,
                shippingTotal: $shippingQuote->amount,
                shippingAddress: $shippingAddress,
                lines: $taxLines,
            ));

            if (! $taxResult->total->isZero()) {
                CartAdjustment::query()->create([
                    'cart_id' => $cart->id,
                    'type' => AdjustmentType::Tax,
                    'label' => 'Tax',
                    'amount_minor' => $taxResult->total->minorAmount,
                    'currency' => $taxResult->total->currency,
                    'origin' => AdjustmentOrigin::System,
                    'affects_total' => true,
                ]);
            }

            $adjustmentsAffectingTotal = CartAdjustment::query()
                ->where('cart_id', $cart->id)
                ->where('affects_total', true)
                ->get();

            $grandTotalMinor = $subtotalMinor + $adjustmentsAffectingTotal->sum('amount_minor');

            $totalsChanged = $cart->subtotal_minor !== $subtotalMinor
                || $cart->discount_total_minor !== abs($manualDiscountMinor)
                || $cart->tax_total_minor !== $taxResult->total->minorAmount
                || $cart->shipping_total_minor !== $shippingQuote->amount->minorAmount
                || $cart->grand_total_minor !== $grandTotalMinor;

            $updated = Cart::query()
                ->where('id', $cart->id)
                ->where('version', $cart->version)
                ->update([
                    'subtotal_minor' => $subtotalMinor,
                    'discount_total_minor' => abs($manualDiscountMinor),
                    'tax_total_minor' => $taxResult->total->minorAmount,
                    'shipping_total_minor' => $shippingQuote->amount->minorAmount,
                    'grand_total_minor' => $grandTotalMinor,
                    'version' => $totalsChanged ? $cart->version + 1 : $cart->version,
                ]);

            if ($updated === 0) {
                throw CartVersionConflictException::for($cart);
            }

            return $cart->fresh(['items', 'adjustments']);
        });
    }

    public function totalsHash(Cart $cart, ?string $shippingMethod = null): string
    {
        $cart->loadMissing('items', 'adjustments');

        return hash('sha256', CanonicalJson::encode([
            'cart_id' => $cart->id,
            'version' => $cart->version,
            'currency' => $cart->currency,
            'shipping_method' => $shippingMethod,
            'items' => $cart->items->map(fn ($i) => [
                'id' => $i->id,
                'purchasable_type' => $i->purchasable_type,
                'purchasable_id' => $i->purchasable_id,
                'quantity' => $i->quantity,
                'unit_price_minor' => $i->unit_price_minor,
            ])->values()->all(),
            'adjustments' => $cart->adjustments->map(fn ($a) => [
                'type' => $a->type->value,
                'amount_minor' => $a->amount_minor,
                'origin' => $a->origin->value,
                'affects_total' => $a->affects_total,
            ])->values()->all(),
            'totals' => [
                'subtotal_minor' => $cart->subtotal_minor,
                'grand_total_minor' => $cart->grand_total_minor,
            ],
        ]));
    }

    private function resolvePriceList(Cart $cart): ?PriceList
    {
        $metadata = $cart->metadata instanceof \ArrayObject
            ? $cart->metadata->getArrayCopy()
            : (array) ($cart->metadata ?? []);
        $priceListId = $metadata['price_list_id'] ?? null;
        if (! is_string($priceListId) || $priceListId === '') {
            return null;
        }

        return PriceList::query()->where('public_id', $priceListId)->first();
    }
}
