<?php

namespace EzEcommerce\Discounts\Actions;

use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartAdjustment;
use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\AdjustmentOrigin;
use EzEcommerce\Core\Enums\AdjustmentType;
use EzEcommerce\Discounts\Models\Discount;
use InvalidArgumentException;

final class ApplyDiscountCode
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    public function execute(Cart $cart, string $code): Cart
    {
        $discount = Discount::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->where(function ($query): void {
                $now = $this->clock->now();
                $query->whereNull('valid_from')->orWhere('valid_from', '<=', $now);
            })
            ->where(function ($query): void {
                $now = $this->clock->now();
                $query->whereNull('valid_to')->orWhere('valid_to', '>=', $now);
            })
            ->first();

        if ($discount === null) {
            throw new InvalidArgumentException("Discount code [{$code}] is invalid or expired.");
        }

        CartAdjustment::query()
            ->where('cart_id', $cart->id)
            ->where('origin', AdjustmentOrigin::Promotion)
            ->where('type', AdjustmentType::Discount)
            ->delete();

        $cart->load('items');
        $subtotalMinor = $cart->items->sum(fn ($item) => $item->unit_price_minor * $item->quantity);

        $discountMinor = match ($discount->type) {
            'percent' => (int) round($subtotalMinor * ($discount->value / 100)),
            'fixed' => min((int) $discount->value, $subtotalMinor),
            default => throw new InvalidArgumentException("Unsupported discount type [{$discount->type}]."),
        };

        if ($discountMinor <= 0) {
            return $cart->fresh(['adjustments']);
        }

        CartAdjustment::query()->create([
            'cart_id' => $cart->id,
            'type' => AdjustmentType::Discount,
            'source_type' => 'commerce_discount',
            'source_id' => $discount->id,
            'code' => $discount->code,
            'label' => 'Discount '.$discount->code,
            'amount_minor' => -$discountMinor,
            'currency' => $cart->currency,
            'origin' => AdjustmentOrigin::Promotion,
            'affects_total' => true,
        ]);

        return $cart->fresh(['adjustments']);
    }
}
