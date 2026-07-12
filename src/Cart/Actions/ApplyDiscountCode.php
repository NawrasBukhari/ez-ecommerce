<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Exceptions\CartVersionConflictException;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartAdjustment;
use EzEcommerce\Core\Contracts\Clock;
use EzEcommerce\Core\Enums\AdjustmentOrigin;
use EzEcommerce\Core\Enums\AdjustmentType;
use EzEcommerce\Discounts\Models\Discount;
use RuntimeException;

final class ApplyDiscountCode
{
    public function __construct(
        private readonly Clock $clock,
    ) {}

    public function execute(Cart $cart, string $code, ?int $expectedVersion = null): Cart
    {
        if ($expectedVersion !== null && $cart->version !== $expectedVersion) {
            throw CartVersionConflictException::for($cart);
        }

        $discount = Discount::query()->where('code', $code)->first();

        if ($discount === null || ! $discount->is_active) {
            throw new RuntimeException("Discount code [{$code}] is not valid.");
        }

        $now = $this->clock->now();
        if ($discount->valid_from !== null && $now < $discount->valid_from) {
            throw new RuntimeException("Discount code [{$code}] is not yet active.");
        }
        if ($discount->valid_to !== null && $now > $discount->valid_to) {
            throw new RuntimeException("Discount code [{$code}] has expired.");
        }

        $cart->loadMissing('items');
        $subtotalMinor = $cart->items->sum(fn ($item) => $item->unit_price_minor * $item->quantity);

        $amountMinor = match ($discount->type) {
            'fixed' => -min(abs((int) $discount->value), $subtotalMinor),
            'percent' => -(int) round($subtotalMinor * abs((int) $discount->value) / 100),
            'percentage' => -(int) round($subtotalMinor * abs((int) $discount->value) / 10000),
            default => throw new RuntimeException("Unsupported discount type [{$discount->type}]."),
        };

        CartAdjustment::query()
            ->where('cart_id', $cart->id)
            ->where('origin', AdjustmentOrigin::Promotion)
            ->where('type', AdjustmentType::Discount)
            ->delete();

        CartAdjustment::query()->create([
            'cart_id' => $cart->id,
            'type' => AdjustmentType::Discount,
            'source_type' => Discount::class,
            'source_id' => $discount->id,
            'code' => $discount->code,
            'label' => $discount->code,
            'amount_minor' => $amountMinor,
            'currency' => $cart->currency,
            'origin' => AdjustmentOrigin::Promotion,
            'affects_total' => true,
        ]);

        $cart->update(['version' => $cart->version + 1]);

        return $cart->fresh(['items', 'adjustments']);
    }
}
