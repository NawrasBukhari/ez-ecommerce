<?php

namespace EzEcommerce\Cart\Actions;

use EzEcommerce\Cart\Exceptions\CartVersionConflictException;
use EzEcommerce\Cart\Models\Cart;
use EzEcommerce\Cart\Models\CartAdjustment;
use EzEcommerce\Core\Enums\AdjustmentOrigin;
use EzEcommerce\Core\Enums\AdjustmentType;

final class RemoveDiscountCode
{
    public function execute(Cart $cart, ?string $code = null, ?int $expectedVersion = null): Cart
    {
        if ($expectedVersion !== null && $cart->version !== $expectedVersion) {
            throw CartVersionConflictException::for($cart);
        }

        $query = CartAdjustment::query()
            ->where('cart_id', $cart->id)
            ->where('origin', AdjustmentOrigin::Promotion)
            ->where('type', AdjustmentType::Discount);

        if ($code !== null) {
            $query->where('code', $code);
        }

        if ($query->exists()) {
            $query->delete();
            $cart->update(['version' => $cart->version + 1]);
        }

        return $cart->fresh(['items', 'adjustments']);
    }
}
